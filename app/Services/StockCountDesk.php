<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\StockCountStatus;
use App\Exceptions\StockCountException;
use App\Models\InventoryAdjustment;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\User;
use App\Support\Quantity;
use App\Support\Reservations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Starts a count sheet, keeps its figures, and posts it when approved
 * (phase 32).
 *
 * Not a poster: a count moves nothing by itself. Approving it hands the
 * differences to `AdjustmentPoster` — what was found over as one increase,
 * what was short as one decrease, both with the reason *count*, at the
 * count's location — so the ledger, the losses on the dashboard and every
 * report read them exactly as they read any adjustment.
 *
 * **The cutoff.** Starting a count takes what the books say is at the
 * location at that moment. A sale after the cutoff is already on the books,
 * so approving posts only the difference — counted less the cutoff figure —
 * on top of whatever is there by then. The till never has to stop. What it
 * cannot know is whether a sale happened before or after somebody counted
 * that shelf, so the sheet shows each line's change since the cutoff for the
 * approver to weigh.
 *
 * Every step runs in one transaction with the count row locked and its
 * status read again under the lock, as `PurchaseRequestDesk` does.
 */
class StockCountDesk
{
    public function __construct(
        protected DocumentNumber $numbers,
        protected AdjustmentPoster $adjustments,
        protected AuditService $audit,
    ) {}

    /**
     * Start a count at a location: every active product (of one category,
     * when one is picked), and anything else still on hand there.
     */
    public function start(int $locationId, ?int $categoryId = null, ?string $notes = null, ?string $idempotencyKey = null): StockCount
    {
        return DB::transaction(function () use ($locationId, $categoryId, $notes, $idempotencyKey) {
            // Locked for update: two people starting a count at the same
            // place at the same moment queue here, and the second finds the
            // first one open.
            $location = Location::whereKey($locationId)->lockForUpdate()->firstOrFail();

            if (! $location->is_active) {
                throw StockCountException::locationOff($location->name);
            }

            $open = StockCount::query()
                ->where('location_id', $location->id)
                ->whereIn('status', StockCountStatus::openValues())
                ->first(['id', 'reference']);

            if ($open) {
                throw StockCountException::alreadyOpen($location->name, $open->reference);
            }

            $productIds = $this->productsToCount($location->id, $categoryId);

            if ($productIds === []) {
                throw StockCountException::nothingToCount($location->name);
            }

            // The cutoff. The product rows are read with a shared lock first
            // — the ledger locks them before it writes — so a document being
            // posted right now is either wholly in the figures or wholly
            // after them, never half.
            Product::whereIn('id', $productIds)->orderBy('id')->sharedLock()->pluck('id');
            $there = Reservations::atLocation($productIds, $location->id, locking: true);
            $cutoff = now();

            $reference = $this->numbers->next('documents.stock_count_prefix', Carbon::today());

            $count = new StockCount([
                'reference' => $reference,
                'location_id' => $location->id,
                'category_id' => $categoryId,
                'status' => StockCountStatus::Counting,
                'count_date' => Carbon::today(),
                'cutoff_at' => $cutoff,
                'notes' => $notes,
                'idempotency_key' => $idempotencyKey,
                'started_by' => auth()->id(),
            ]);

            $this->audit->withoutAuditing(fn () => $count->save());

            // Inserted in chunks: a whole store may be a few thousand lines.
            foreach (array_chunk($productIds, 500) as $chunk) {
                StockCountLine::insert(array_map(fn (int $id) => [
                    'stock_count_id' => $count->id,
                    'product_id' => $id,
                    'system_quantity' => Quantity::toDb($there[$id] ?? 0),
                    'created_at' => $cutoff,
                    'updated_at' => $cutoff,
                ], $chunk));
            }

            $count->auditCustom(
                AuditAction::Create,
                "Started stock count [{$reference}] at {$location->name}: ".count($productIds).' product(s) to count.',
                ['location' => $location->name, 'category_id' => $categoryId, 'lines' => count($productIds)],
            );

            return $count;
        }, attempts: 3);
    }

    /**
     * Write what was counted. Each figure is a line id and a quantity, or
     * null to clear one entered by mistake. Only while counting.
     *
     * @param  array<int, int|float|string|null>  $figures  Line id => counted.
     */
    public function record(StockCount $count, array $figures, User $by): int
    {
        return DB::transaction(function () use ($count, $figures, $by) {
            $locked = $this->lock($count);

            if (! $locked->isCounting()) {
                throw StockCountException::notCounting($locked->reference, strtolower($locked->status->label()));
            }

            $lines = $locked->lines()->whereIn('id', array_keys($figures))->get()->keyBy('id');

            if ($lines->count() !== count($figures)) {
                throw StockCountException::notOnSheet();
            }

            $changed = 0;

            foreach ($figures as $id => $counted) {
                $line = $lines[$id];
                $was = $line->counted_quantity;

                $same = $counted === null
                    ? $was === null
                    : $was !== null && Quantity::toMilli($was) === Quantity::toMilli($counted);

                if ($same) {
                    continue;
                }

                $line->forceFill([
                    'counted_quantity' => $counted,
                    'counted_at' => $counted === null ? null : now(),
                    'counted_by' => $counted === null ? null : $by->id,
                ])->save();

                $changed++;
            }

            // Counting is many small saves. One trail row per save says who
            // counted, without a row for every shelf.
            if ($changed > 0) {
                $locked->auditCustom(
                    AuditAction::Update,
                    "Entered {$changed} counted figure(s) on stock count [{$locked->reference}].",
                    ['lines' => $changed],
                );
            }

            return $changed;
        }, attempts: 3);
    }

    /** Hand the sheet in for approval. At least one line must be counted. */
    public function submit(StockCount $count, User $by): StockCount
    {
        return DB::transaction(function () use ($count, $by) {
            $locked = $this->lock($count);

            if (! $locked->isCounting()) {
                throw StockCountException::notCounting($locked->reference, strtolower($locked->status->label()));
            }

            $counted = $locked->lines()->whereNotNull('counted_quantity')->count();

            if ($counted === 0) {
                throw StockCountException::nothingCounted($locked->reference);
            }

            $left = $locked->lines()->whereNull('counted_quantity')->count();

            $this->audit->withoutAuditing(fn () => $locked->forceFill([
                'status' => StockCountStatus::Submitted,
                'submitted_at' => now(),
                'submitted_by' => $by->id,
            ])->save());

            $locked->auditCustom(
                AuditAction::Update,
                "Handed in stock count [{$locked->reference}] for approval: {$counted} counted"
                    .($left > 0 ? ", {$left} left uncounted." : '.'),
                ['counted' => $counted, 'uncounted' => $left],
            );

            $this->refreshTheFigures();

            return $locked->fresh();
        }, attempts: 3);
    }

    /**
     * Send it back to be counted again, with the reason. The figures stay,
     * so only the lines in doubt need counting again.
     */
    public function sendBack(StockCount $count, User $by, string $reason): StockCount
    {
        return DB::transaction(function () use ($count, $by, $reason) {
            $locked = $this->lock($count);

            if (! $locked->isSubmitted()) {
                throw StockCountException::notSubmitted($locked->reference, strtolower($locked->status->label()));
            }

            $this->audit->withoutAuditing(fn () => $locked->forceFill([
                'status' => StockCountStatus::Counting,
                'sent_back_at' => now(),
                'sent_back_by' => $by->id,
                'send_back_reason' => $reason,
                'submitted_at' => null,
                'submitted_by' => null,
            ])->save());

            $locked->auditCustom(
                AuditAction::Reject,
                "Sent stock count [{$locked->reference}] back for a recount: {$reason}",
                ['reason' => $reason],
            );

            $this->refreshTheFigures();

            return $locked->fresh();
        }, attempts: 3);
    }

    /**
     * Approve it: post what was over and what was short.
     *
     * Each counted line's difference is counted less the figure at the
     * cutoff, never counted less what is there now — anything sold or
     * received since is already on the books. A line left uncounted is
     * left as it is. Nothing over or short posts nothing, and the count is
     * still approved.
     *
     * The shortfall comes off the oldest batches, like any decrease, and the
     * ledger refuses it when the location now holds less than it — then the
     * whole approval rolls back and the count stays waiting.
     */
    public function approve(StockCount $count, User $by): StockCount
    {
        return DB::transaction(function () use ($count, $by) {
            $locked = $this->lock($count);

            if (! $locked->isSubmitted()) {
                throw StockCountException::notSubmitted($locked->reference, strtolower($locked->status->label()));
            }

            $location = Location::whereKey($locked->location_id)->first();

            if (! $location?->is_active) {
                throw StockCountException::locationOff($location?->name ?? 'The location');
            }

            $lines = $locked->lines()
                ->whereNotNull('counted_quantity')
                ->with('product:id,cost')
                ->orderBy('id')
                ->get();

            $over = [];
            $short = [];

            foreach ($lines as $line) {
                $difference = Quantity::toMilli($line->counted_quantity) - Quantity::toMilli($line->system_quantity);

                if ($difference > 0) {
                    $over[$line->id] = $difference;
                } elseif ($difference < 0) {
                    $short[$line->id] = -$difference;
                }
            }

            $byId = $lines->keyBy('id');
            $note = "From stock count [{$locked->reference}].";

            $gain = $over === [] ? null : $this->adjustments->post(
                InventoryAdjustment::KIND_INCREASE,
                $this->adjustmentLines($over, $byId, withCost: true),
                Carbon::today(),
                'count',
                $note,
                null,
                $location->id,
            );

            $loss = $short === [] ? null : $this->adjustments->post(
                InventoryAdjustment::KIND_DECREASE,
                $this->adjustmentLines($short, $byId, withCost: false),
                Carbon::today(),
                'count',
                $note,
                null,
                $location->id,
            );

            // Each line's difference, and what it came to at cost, from the
            // adjustment lines the ledger costed.
            $gainValue = $this->writeVariances($gain, array_keys($over), $byId, sign: 1);
            $lossValue = $this->writeVariances($loss, array_keys($short), $byId, sign: -1);

            // The lines that matched, in one statement.
            $locked->lines()
                ->whereNotNull('counted_quantity')
                ->whereNotIn('id', [...array_keys($over), ...array_keys($short)])
                ->update(['variance' => 0, 'variance_value' => 0]);

            $own = $locked->isOwnedBy($by);

            $this->audit->withoutAuditing(fn () => $locked->forceFill([
                'status' => StockCountStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $by->id,
                'gain_adjustment_id' => $gain?->id,
                'loss_adjustment_id' => $loss?->id,
                'gain_value' => $gainValue,
                'loss_value' => $lossValue,
            ])->save());

            $locked->auditCustom(
                AuditAction::Approve,
                'Approved'.($own ? ' their own' : '')." stock count [{$locked->reference}]: "
                    .count($over).' over, '.count($short).' short'
                    .($gain || $loss ? ', posted as '.implode(' and ', array_filter([$gain?->reference, $loss?->reference])).'.' : ', nothing to post.'),
                [
                    'over' => count($over), 'short' => count($short),
                    'gain_value' => $gainValue, 'loss_value' => $lossValue, 'own_count' => $own,
                ],
            );

            $this->refreshTheFigures();

            return $locked->fresh();
        }, attempts: 3);
    }

    /** Give it up. Nothing is posted; the location is free for a new count. */
    public function cancel(StockCount $count, User $by, string $reason): StockCount
    {
        return DB::transaction(function () use ($count, $by, $reason) {
            $locked = $this->lock($count);

            if (! $locked->isOpen()) {
                throw StockCountException::notOpen($locked->reference, strtolower($locked->status->label()));
            }

            $this->audit->withoutAuditing(fn () => $locked->forceFill([
                'status' => StockCountStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $reason,
            ])->save());

            $locked->auditCustom(
                AuditAction::Cancel,
                "Cancelled stock count [{$locked->reference}]: {$reason}",
                ['reason' => $reason],
            );

            $this->refreshTheFigures();

            return $locked->fresh();
        }, attempts: 3);
    }

    /**
     * Every product a new count lists, in id order: the active ones, and any
     * switched off since that still has stock at the location.
     *
     * @return list<int>
     */
    public function productsToCount(int $locationId, ?int $categoryId): array
    {
        $holding = DB::table('location_stocks')
            ->where('location_id', $locationId)
            ->where('on_hand', '!=', 0)
            ->select('product_id');

        return Product::query()
            ->when($categoryId, fn ($q, $id) => $q->where('category_id', $id))
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $holding))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function lock(StockCount $count): StockCount
    {
        return StockCount::whereKey($count->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<int, int>  $milli  Line id => quantity, in thousandths.
     * @param  Collection<int, StockCountLine>  $lines
     * @return list<array{product_id:int, quantity:string, unit_cost?:float}>
     */
    protected function adjustmentLines(array $milli, $lines, bool $withCost): array
    {
        $out = [];

        foreach ($milli as $id => $quantity) {
            $line = $lines[$id];

            // What was found over comes in at what the product costs now,
            // as an increase typed on the adjustment form starts at.
            $out[] = ['product_id' => (int) $line->product_id, 'quantity' => Quantity::toDb($quantity)]
                + ($withCost ? ['unit_cost' => round((float) $line->product?->cost, 2)] : []);
        }

        return $out;
    }

    /**
     * Copy each adjustment line's cost back onto the count line it came
     * from. The adjustment's lines are in the order they were sent.
     *
     * @param  list<int>  $lineIds
     * @param  Collection<int, StockCountLine>  $lines
     */
    protected function writeVariances(?InventoryAdjustment $adjustment, array $lineIds, $lines, int $sign): float
    {
        if (! $adjustment) {
            return 0.0;
        }

        $costed = $adjustment->lines()->orderBy('id')->get(['quantity', 'total_cost'])->values();
        $total = 0;

        foreach ($lineIds as $index => $id) {
            $posted = $costed[$index];
            $value = Quantity::centavos($posted->total_cost);
            $total += $value;

            $lines[$id]->forceFill([
                'variance' => Quantity::fromMilli($sign * Quantity::toMilli($posted->quantity)),
                'variance_value' => $sign * $value / 100,
            ])->save();
        }

        return $total / 100;
    }

    /**
     * Approving posts adjustments, which flush the figures already. Handing
     * in and cancelling change what waits for approval, so they flush too,
     * once committed.
     */
    protected function refreshTheFigures(): void
    {
        DB::afterCommit(fn () => app(InventoryMetrics::class)->flush());
    }
}
