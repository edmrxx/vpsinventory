<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\LossReportStatus;
use App\Exceptions\LossReportException;
use App\Models\InventoryAdjustment;
use App\Models\Location;
use App\Models\LossReport;
use App\Models\LossReportLine;
use App\Models\Product;
use App\Models\User;
use App\Support\LossPhoto;
use App\Support\Quantity;
use App\Support\Reservations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reports a loss, and records the answer to one (phase 33).
 *
 * Not a poster. Reporting moves nothing: the goods are **held aside** at
 * their location — still on the books, but `Reservations` counts them as
 * taken, so no sale, transfer or customer order may have them. Approving
 * hands them to `AdjustmentPoster` as one decrease with the report's reason,
 * so the ledger, the Losses on the dashboard and every report read it as any
 * write-off. Rejecting or withdrawing frees them again.
 *
 * Reporting locks the way a sales order's approval does: the product rows
 * first, ascending, then the location with a shared lock, then what is free
 * there with a locking read, then the number. A sale posting at the same
 * moment either finished first — and the report sees less free — or waits,
 * and then refuses to take what the report holds.
 */
class LossReportDesk
{
    public function __construct(
        protected DocumentNumber $numbers,
        protected AdjustmentPoster $adjustments,
        protected AuditService $audit,
    ) {}

    /**
     * @param  list<array{product_id:int, quantity:int|float|string}>  $lines
     * @param  list<UploadedFile>  $photos
     */
    public function report(int $locationId, string $reason, array $lines, array $photos = [], ?string $notes = null, ?string $idempotencyKey = null): LossReport
    {
        $lines = array_values($lines);
        $stored = [];

        try {
            // Written before the transaction, so a slow upload does not hold
            // the product rows; removed again below if the report fails.
            foreach ($photos as $photo) {
                $stored[] = ['path' => LossPhoto::store($photo), 'original_name' => mb_substr($photo->getClientOriginalName(), 0, 255)];
            }

            return DB::transaction(function () use ($locationId, $reason, $lines, $stored, $notes, $idempotencyKey) {
                $wanted = [];

                foreach ($lines as $line) {
                    $id = (int) $line['product_id'];
                    $wanted[$id] = ($wanted[$id] ?? 0) + Quantity::toMilli($line['quantity']);
                }

                $products = Product::whereIn('id', array_keys($wanted))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'sku', 'unit_id'])
                    ->keyBy('id');

                $location = Location::whereKey($locationId)->sharedLock()->first();

                if (! $location?->is_active) {
                    throw LossReportException::locationOff();
                }

                // What is free there now: on hand, less what orders and
                // other reports already hold.
                $there = Reservations::atLocation(array_keys($wanted), $location->id, locking: true);
                $held = Reservations::reserved(array_keys($wanted), locking: true, locationId: $location->id);

                foreach ($wanted as $productId => $milli) {
                    $free = max(0, ($there[$productId] ?? 0) - ($held[$productId] ?? 0));

                    if ($milli > $free) {
                        throw LossReportException::notEnoughFree(
                            (string) $products[$productId]?->sku,
                            $location->name,
                            Quantity::format(Quantity::fromMilli($free)),
                        );
                    }
                }

                $reference = $this->numbers->next('documents.loss_report_prefix', Carbon::today());

                $report = new LossReport([
                    'reference' => $reference,
                    'location_id' => $location->id,
                    'reason' => $reason,
                    'status' => LossReportStatus::Pending,
                    'report_date' => Carbon::today(),
                    'notes' => $notes,
                    'idempotency_key' => $idempotencyKey,
                    'reported_by' => auth()->id(),
                ]);

                $this->audit->withoutAuditing(fn () => $report->save());

                foreach ($lines as $line) {
                    LossReportLine::create([
                        'loss_report_id' => $report->id,
                        'product_id' => (int) $line['product_id'],
                        'quantity' => $line['quantity'],
                    ]);
                }

                foreach ($stored as $photo) {
                    $report->photos()->create($photo);
                }

                $report->auditCustom(
                    AuditAction::Create,
                    "Reported loss [{$reference}] at {$location->name}: ".count($lines).' line(s), '
                        .$report->reasonLabel().', '.count($stored).' photo(s). The goods are held until it is answered.',
                    ['reason' => $reason, 'lines' => count($lines), 'photos' => count($stored)],
                );

                $this->refreshTheFigures();

                return $report;
            }, attempts: 3);
        } catch (\Throwable $e) {
            LossPhoto::deleteMany(array_column($stored, 'path'));

            throw $e;
        }
    }

    /**
     * Write it off: post one decrease adjustment, at the report's location,
     * with its reason. The ledger refuses it when the goods are no longer
     * there — a purchase return or a void does not look at holds — and then
     * the report keeps waiting.
     */
    public function approve(LossReport $report, User $by, ?string $note = null): LossReport
    {
        return DB::transaction(function () use ($report, $by, $note) {
            $locked = $this->lock($report);

            if (! $locked->isPending()) {
                throw LossReportException::notPending($locked->reference, strtolower($locked->status->label()));
            }

            $location = Location::whereKey($locked->location_id)->first();

            if (! $location?->is_active) {
                throw LossReportException::locationOff();
            }

            // Its status changes first, so the hold it keeps is gone before
            // the write-off reads what is free.
            $lines = $locked->lines()->orderBy('id')->get();
            $note = trim((string) $note) ?: null;

            $this->audit->withoutAuditing(fn () => $locked->forceFill([
                'status' => LossReportStatus::Approved,
                'decided_at' => now(),
                'decided_by' => $by->id,
                'decision_note' => $note,
            ])->save());

            $adjustment = $this->adjustments->post(
                InventoryAdjustment::KIND_DECREASE,
                $lines->map(fn (LossReportLine $l) => [
                    'product_id' => (int) $l->product_id,
                    'quantity' => Quantity::toDb(Quantity::toMilli($l->quantity)),
                ])->all(),
                Carbon::today(),
                $locked->reason,
                "From loss report [{$locked->reference}].",
                null,
                $location->id,
            );

            // The cost each line came to, from the batches it ate.
            $costed = $adjustment->lines()->orderBy('id')->get(['unit_cost', 'total_cost'])->values();

            foreach ($lines->values() as $index => $line) {
                $line->forceFill([
                    'unit_cost' => $costed[$index]->unit_cost,
                    'total_cost' => $costed[$index]->total_cost,
                ])->save();
            }

            $locked->forceFill([
                'adjustment_id' => $adjustment->id,
                'total_cost' => $adjustment->total_cost,
            ])->saveQuietly();

            $own = $locked->wasReportedBy($by);

            $locked->auditCustom(
                AuditAction::Approve,
                'Approved'.($own ? ' their own' : '')." loss report [{$locked->reference}]: written off as {$adjustment->reference}"
                    .($note ? ": {$note}" : '.'),
                ['adjustment' => $adjustment->reference, 'total_cost' => (float) $adjustment->total_cost, 'own_report' => $own],
            );

            $this->refreshTheFigures();

            return $locked->fresh();
        }, attempts: 3);
    }

    /** Say no, and why. The goods are free to sell again. */
    public function reject(LossReport $report, User $by, string $reason): LossReport
    {
        return DB::transaction(function () use ($report, $by, $reason) {
            $locked = $this->lock($report);

            if (! $locked->isPending()) {
                throw LossReportException::notPending($locked->reference, strtolower($locked->status->label()));
            }

            $this->audit->withoutAuditing(fn () => $locked->forceFill([
                'status' => LossReportStatus::Rejected,
                'decided_at' => now(),
                'decided_by' => $by->id,
                'decision_note' => $reason,
            ])->save());

            $locked->auditCustom(
                AuditAction::Reject,
                "Rejected loss report [{$locked->reference}]: {$reason}. The goods are free again.",
                ['reason' => $reason],
            );

            $this->refreshTheFigures();

            return $locked->fresh();
        }, attempts: 3);
    }

    /** Take it back before an answer. The goods are free again. */
    public function cancel(LossReport $report, User $by, string $reason): LossReport
    {
        return DB::transaction(function () use ($report, $by, $reason) {
            $locked = $this->lock($report);

            if (! $locked->isPending()) {
                throw LossReportException::notPending($locked->reference, strtolower($locked->status->label()));
            }

            $this->audit->withoutAuditing(fn () => $locked->forceFill([
                'status' => LossReportStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $reason,
            ])->save());

            $locked->auditCustom(
                AuditAction::Cancel,
                "Withdrew loss report [{$locked->reference}]: {$reason}. The goods are free again.",
                ['reason' => $reason],
            );

            $this->refreshTheFigures();

            return $locked->fresh();
        }, attempts: 3);
    }

    protected function lock(LossReport $report): LossReport
    {
        return LossReport::whereKey($report->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * A hold changes what is free on the Stock screen and what the
     * dashboard reads, so the cached figures go once committed.
     */
    protected function refreshTheFigures(): void
    {
        DB::afterCommit(fn () => app(InventoryMetrics::class)->flush());
    }
}
