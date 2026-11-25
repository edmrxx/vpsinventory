<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Exceptions\PurchaseOrderException;
use App\Exceptions\PurchaseRequestException;
use App\Models\Location;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Quantity;
use App\Support\UnitConversion;
use App\Support\Vat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Places a purchase order, approves or rejects it (phase 24.4), and cancels
 * one (phase 22).
 *
 * Nothing is posted: no stock moves and nobody owes anything until the
 * delivery is posted under Purchases. What it shares with the posters is the
 * shape — one transaction, the document number taken inside it, every figure
 * worked out here whatever the form sent.
 *
 * Placed from a request, it takes its locks in this order: the request row,
 * then the document number. It reads the request's status again under that
 * lock, so two people ordering the same request cannot both get through.
 * The request is closed in the same transaction, saying which order (or,
 * from an award, which orders) it became.
 *
 * An order is approved before it is sent (phase 24.4). It starts *For
 * approval*, unless whoever places it may approve orders and the setting
 * `purchasing.self_approval` lets them approve their own — then it is
 * approved at once, and the trail says so. Until it is approved nothing can
 * be received against it.
 *
 * The dashboard's Orders to approve card counts what is waiting, so every
 * step here flushes the cached figures once the transaction has committed.
 */
class PurchaseOrderDesk
{
    public function __construct(
        protected DocumentNumber $numbers,
        protected AuditService $audit,
    ) {}

    /**
     * @param  array{supplier_id:int, order_date:string|Carbon, deliver_to_location_id?:int|null, expected_date?:string|null, notes?:string|null, idempotency_key?:string|null, purchase_request_id?:int|null}  $header
     * @param  list<array{product_id:int, unit_id?:int|null, quantity:int|float|string, unit_cost:float|string}>  $lines
     */
    public function place(array $header, array $lines, User $by): PurchaseOrder
    {
        $lines = array_values($lines);

        $date = $header['order_date'] instanceof Carbon
            ? $header['order_date']->copy()->startOfDay()
            : Carbon::parse($header['order_date'])->startOfDay();

        return DB::transaction(function () use ($header, $lines, $date, $by) {
            $request = null;

            if (! empty($header['purchase_request_id'])) {
                $request = PurchaseRequest::whereKey($header['purchase_request_id'])->lockForUpdate()->firstOrFail();

                if ($ordered = PurchaseOrder::where('purchase_request_id', $request->id)->value('reference')) {
                    throw PurchaseOrderException::requestAlreadyOrdered($request->reference, $ordered);
                }

                if (! $request->isApproved()) {
                    throw PurchaseOrderException::requestNotApproved($request->reference, strtolower($request->status->label()));
                }

                if ($request->isAwarded()) {
                    throw PurchaseOrderException::requestAwarded($request->reference);
                }
            }

            $order = $this->write($header, $this->price($lines), $date, $request, $by);

            if ($request) {
                $this->closeRequest($request, [$order->reference], $by);
            }

            $this->refreshTheFigures();

            return $order->fresh(['lines', 'supplier']);
        }, attempts: 3);
    }

    /**
     * The orders an awarded request becomes (phase 24.3): one for each
     * supplier that won an item, at the prices it quoted, in the unit each
     * item was asked in. An item nobody quoted is not ordered, and the
     * request says so when it closes.
     *
     * Locks the request row, reads again that it is approved, awarded and not
     * yet ordered, then takes one number per order — so two people pressing
     * the button at once cannot order the award twice. Each order expects
     * delivery after the days its supplier quoted, when it said.
     *
     * @return list<PurchaseOrder>
     */
    public function placeFromAward(PurchaseRequest $request, User $by): array
    {
        return DB::transaction(function () use ($request, $by) {
            $locked = PurchaseRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if ($ordered = PurchaseOrder::where('purchase_request_id', $locked->id)->value('reference')) {
                throw PurchaseOrderException::requestAlreadyOrdered($locked->reference, $ordered);
            }

            if (! $locked->isApproved()) {
                throw PurchaseOrderException::requestNotApproved($locked->reference, strtolower($locked->status->label()));
            }

            if (! $locked->isAwarded()) {
                throw PurchaseOrderException::requestNotAwarded($locked->reference);
            }

            $lines = PurchaseRequestLine::query()
                ->where('purchase_request_id', $locked->id)
                ->orderBy('id')
                ->with('awardedQuoteLine.quote')
                ->get();

            // Grouped by supplier, in the order their quotes were recorded.
            $bySupplier = $lines
                ->filter(fn ($line) => $line->awardedQuoteLine !== null)
                ->sortBy(fn ($line) => $line->awardedQuoteLine->supplier_quote_id)
                ->groupBy(fn ($line) => $line->awardedQuoteLine->quote->supplier_id);

            if ($bySupplier->isEmpty()) {
                throw PurchaseRequestException::nothingAwarded($locked->reference);
            }

            $date = Carbon::today();
            $orders = [];

            foreach ($bySupplier as $supplierId => $won) {
                $quote = $won->first()->awardedQuoteLine->quote;

                $priced = $this->price($won->map(fn ($line) => [
                    'product_id' => $line->product_id,
                    'unit_id' => $line->unit_id,
                    'quantity' => $line->unit_quantity,
                    'unit_cost' => $line->awardedQuoteLine->unit_price,
                ])->values()->all());

                foreach ($won->values() as $index => $line) {
                    $priced[$index]['purchase_request_line_id'] = $line->id;
                }

                $orders[] = $this->write([
                    'supplier_id' => (int) $supplierId,
                    // Delivered where every delivery posts until a location
                    // is picked on receiving (phase 31.3); the receiver may
                    // still change it.
                    'deliver_to_location_id' => Location::default()?->id,
                    'expected_date' => $quote->delivery_days !== null ? $date->copy()->addDays($quote->delivery_days)->toDateString() : null,
                    'notes' => "From the award on request [{$locked->reference}]."
                        .($quote->payment_terms ? " Payment terms quoted: {$quote->payment_terms}." : ''),
                ], $priced, $date, $locked, $by);
            }

            $left = $lines->filter(fn ($line) => $line->awardedQuoteLine === null)->count();

            $this->closeRequest(
                $locked,
                array_map(fn (PurchaseOrder $order) => $order->reference, $orders),
                $by,
                $left > 0 ? " {$left} item(s) nobody quoted were not ordered." : '',
            );

            $this->refreshTheFigures();

            return array_map(fn (PurchaseOrder $order) => $order->fresh(['lines', 'supplier']), $orders);
        }, attempts: 3);
    }

    /**
     * Write one order and its lines, and its line in the trail. The caller
     * holds the request lock, if there is a request.
     *
     * The one place an order's approval is decided as it is placed: approved
     * at once only when the person placing it may approve orders and may
     * approve their own (`purchasing.self_approval`); otherwise it waits.
     *
     * @param  array{supplier_id:int, deliver_to_location_id?:int|null, expected_date?:string|null, notes?:string|null, idempotency_key?:string|null}  $header
     * @param  list<array<string, mixed>>  $priced
     */
    protected function write(array $header, array $priced, Carbon $date, ?PurchaseRequest $request, User $by): PurchaseOrder
    {
        $reference = $this->numbers->next('documents.purchase_order_prefix', $date);
        $total = array_sum(array_map(fn (array $line) => Quantity::centavos($line['line_total']), $priced));
        $approvedAtOnce = static::approvesOwn($by);

        $order = new PurchaseOrder([
            'reference' => $reference,
            'supplier_id' => $header['supplier_id'],
            'deliver_to_location_id' => $header['deliver_to_location_id'] ?? null,
            'purchase_request_id' => $request?->id,
            'order_date' => $date,
            'expected_date' => $header['expected_date'] ?? null,
            'status' => $approvedAtOnce ? PurchaseOrderStatus::Open : PurchaseOrderStatus::PendingApproval,
            'total_amount' => $total / 100,
            // Stamped from the supplier as they stand today, the way a
            // purchase is: whether *they* charge VAT, not whether we do.
            'vat_rate' => Supplier::whereKey($header['supplier_id'])->value('is_vat_registered') ? Vat::rate() : 0,
            'notes' => $header['notes'] ?? null,
            'idempotency_key' => $header['idempotency_key'] ?? null,
            'ordered_by' => $by->id,
            'approved_at' => $approvedAtOnce ? now() : null,
            'approved_by' => $approvedAtOnce ? $by->id : null,
        ]);

        $this->audit->withoutAuditing(fn () => $order->save());

        foreach ($priced as $line) {
            PurchaseOrderLine::create(['purchase_order_id' => $order->id] + $line);
        }

        $order->auditCustom(
            AuditAction::Create,
            "Placed purchase order [{$reference}]: ".count($priced).' line(s), '
                .number_format($total / 100, 2)
                .match (true) {
                    $request !== null && $request->isAwarded() => ", from the award on request [{$request->reference}].",
                    $request !== null => ", from request [{$request->reference}].",
                    default => '.',
                }
            .($approvedAtOnce ? '' : ' Waiting for approval.'),
            ['lines' => count($priced), 'total_amount' => $total / 100, 'request' => $request?->reference],
        );

        // Approving one's own order is allowed by a setting, and said
        // plainly in the trail, so it can always be found again.
        if ($approvedAtOnce) {
            $order->auditCustom(
                AuditAction::Approve,
                "Approved their own purchase order [{$reference}] as it was placed.",
                ['own_order' => true, 'total_amount' => $total / 100],
            );
        }

        return $order;
    }

    /**
     * May this person's own orders be approved as they place them? They may
     * approve orders, and the setting lets an approver answer their own.
     */
    public static function approvesOwn(User $by): bool
    {
        return $by->can('purchase_orders.approve') && PurchaseRequest::selfApprovalAllowed();
    }

    /** Say yes: the order may now be sent, and received against. */
    public function approve(PurchaseOrder $order, User $by): PurchaseOrder
    {
        return $this->decide($order, $by, true, null);
    }

    /** Say no, and why. Final: the goods are ordered again on a new order. */
    public function reject(PurchaseOrder $order, User $by, string $reason): PurchaseOrder
    {
        return $this->decide($order, $by, false, $reason);
    }

    /**
     * Answer an order waiting for approval. Read again under the row lock,
     * so Approve and Reject pressed at once cannot both get through; the
     * rule on one's own orders is asked here as well as in the policy,
     * because a rule only the screen keeps is not kept.
     *
     * A rejected order leaves the request it came from closed, as a
     * cancelled one does: it was ordered, and the trail says so.
     */
    protected function decide(PurchaseOrder $order, User $by, bool $approved, ?string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $by, $approved, $reason) {
            $locked = PurchaseOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPendingApproval()) {
                throw PurchaseOrderException::notPendingApproval($locked->reference, strtolower($locked->status->label()));
            }

            $own = $locked->wasPlacedBy($by);

            if ($own && ! PurchaseRequest::selfApprovalAllowed()) {
                throw PurchaseOrderException::ownOrder($locked->reference);
            }

            $this->audit->withoutAuditing(fn () => $locked->forceFill($approved
                ? ['status' => PurchaseOrderStatus::Open, 'approved_at' => now(), 'approved_by' => $by->id]
                : ['status' => PurchaseOrderStatus::Rejected, 'rejected_at' => now(), 'rejected_by' => $by->id, 'reject_reason' => $reason]
            )->save());

            $locked->auditCustom(
                $approved ? AuditAction::Approve : AuditAction::Reject,
                ($approved ? 'Approved' : 'Rejected')
                    .($own ? ' their own' : '')
                    ." purchase order [{$locked->reference}]"
                    .($reason ? ": {$reason}" : '.'),
                ['reason' => $reason, 'total_amount' => (float) $locked->total_amount, 'own_order' => $own],
            );

            $this->refreshTheFigures();

            return $locked->fresh();
        }, attempts: 3);
    }

    /**
     * Close the request it came from, naming the orders.
     *
     * @param  list<string>  $references
     */
    protected function closeRequest(PurchaseRequest $request, array $references, User $by, string $extra = ''): void
    {
        $reason = 'Ordered on ['.implode('], [', $references).'].'.$extra;

        $this->audit->withoutAuditing(fn () => $request->forceFill([
            'status' => PurchaseRequestStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $by->id,
            'close_reason' => $reason,
        ])->save());

        $request->auditCustom(AuditAction::Close, "Closed purchase request [{$request->reference}]: {$reason}", [
            'reason' => $reason,
            'orders' => $references,
        ]);
    }

    /**
     * Call an order off, with a reason: an open one, or one still waiting
     * for approval (phase 24.4). A rejected one is already final.
     *
     * Only while nothing has been received against it: once goods are in,
     * the order is closed short instead. The request it came from stays
     * closed: it was ordered, and the trail says so. Ordering the goods
     * elsewhere is a new order, placed directly.
     */
    public function cancel(PurchaseOrder $order, User $by, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $by, $reason) {
            $locked = PurchaseOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen() && ! $locked->isPendingApproval()) {
                throw PurchaseOrderException::notOpen($locked->reference, strtolower($locked->status->label()));
            }

            $this->audit->withoutAuditing(fn () => $locked->forceFill([
                'status' => PurchaseOrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $reason,
            ])->save());

            $locked->auditCustom(
                AuditAction::Cancel,
                "Cancelled purchase order [{$locked->reference}]: {$reason}",
                ['reason' => $reason, 'total_amount' => (float) $locked->total_amount],
            );

            $this->refreshTheFigures();

            return $locked->fresh();
        }, attempts: 3);
    }

    /**
     * Close a partly received order short: the rest is not coming.
     *
     * What did arrive stays received; the order stops counting as
     * outstanding and no more can be posted against it. An order with
     * nothing received is cancelled instead.
     */
    public function closeShort(PurchaseOrder $order, User $by, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $by, $reason) {
            $locked = PurchaseOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== PurchaseOrderStatus::PartlyReceived) {
                throw PurchaseOrderException::notPartlyReceived($locked->reference, strtolower($locked->status->label()));
            }

            $this->audit->withoutAuditing(fn () => $locked->forceFill([
                'status' => PurchaseOrderStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $by->id,
                'close_reason' => $reason,
            ])->save());

            $locked->auditCustom(
                AuditAction::Close,
                "Closed purchase order [{$locked->reference}] short: {$reason}",
                ['reason' => $reason],
            );

            return $locked->fresh();
        }, attempts: 3);
    }

    /**
     * Every line as it is stored: what was typed, what that is in the
     * product's own unit, and the agreed cost.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{product_id:int, unit_id:?int, unit_factor:int|float, unit_quantity:int|float, quantity:string, unit_cost:float, line_total:float}>
     */
    protected function price(array $lines): array
    {
        $converted = UnitConversion::resolve($lines);
        $priced = [];

        foreach ($lines as $index => $line) {
            $unit = $converted[$index];

            if ($unit['error'] !== null || $unit['base'] === null || $unit['base'] <= 0) {
                throw PurchaseOrderException::cannotConvert(
                    $unit['error'] ?? 'A line could not be read in its product\'s own unit.'
                );
            }

            $typed = Quantity::normalize($line['quantity']);
            $cost = round((float) $line['unit_cost'], 2);

            $priced[] = [
                'product_id' => (int) $line['product_id'],
                'unit_id' => $unit['unit_id'],
                'unit_factor' => $unit['factor'],
                'unit_quantity' => $typed,
                'quantity' => Quantity::toDb($unit['base']),
                'unit_cost' => $cost,
                'line_total' => Quantity::lineTotal($typed, $cost),
            ];
        }

        return $priced;
    }

    /** The dashboard counts orders waiting for approval. After commit, never inside. */
    protected function refreshTheFigures(): void
    {
        DB::afterCommit(fn () => app(InventoryMetrics::class)->flush());
    }
}
