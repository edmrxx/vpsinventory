<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\SalesOrderStatus;
use App\Exceptions\InventoryException;
use App\Exceptions\SalesOrderException;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\User;
use App\Support\Quantity;
use App\Support\Reservations;
use App\Support\SaleTotals;
use App\Support\UnitConversion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Takes a customer's order, approves it — which reserves its stock —
 * cancels it, and marks it fulfilled when it becomes a sale (phase 29).
 *
 * Nothing moves on the shelf here: the stock leaves when the sale posts.
 * What it decides is what is held. Approving locks the products the order
 * names, in ascending id order, **before** the order row, then reads what
 * other orders hold with a shared lock and refuses to hold what is not free.
 * A sale posting at the same moment locks those products too, so the two
 * wait for each other rather than both counting the same pieces.
 *
 * Fulfilling is done inside the sale's own transaction by `SalePoster`,
 * which asks `lockedForSale()` here first and `fulfil()` once it has posted.
 */
class SalesOrderDesk
{
    public function __construct(
        protected DocumentNumber $numbers,
        protected AuditService $audit,
        protected QuotationDesk $quotations,
    ) {}

    /**
     * Take an order. Pending unless `approve` is asked for by somebody who may
     * approve, in which case it is approved — its stock reserved — in the
     * same step, or refused whole when the stock is not free.
     *
     * @param  array{customer_id:int, attention?:string|null, reserve_until:string|Carbon, discount?:float|string|null, notes?:string|null, idempotency_key?:string|null, quotation_id?:int|null, approve?:bool}  $header
     * @param  list<array{product_id:int, unit_id?:int|null, quantity:int|float|string, unit_price:float|string}>  $lines
     */
    public function create(array $header, array $lines, User $by): SalesOrder
    {
        $lines = array_values($lines);
        $today = Carbon::today();

        return DB::transaction(function () use ($header, $lines, $today, $by) {
            // From a quotation: lock it and check it, before anything else.
            $quotation = ! empty($header['quotation_id'])
                ? $this->quotations->lockedForSale((int) $header['quotation_id'], (int) $header['customer_id'], $today)
                : null;

            $priced = $this->price($lines);
            $totals = SaleTotals::of($lines, $header['discount'] ?? 0, $header['mobilization'] ?? 0);

            // Where it holds its stock (phase 31.4), read with a shared lock
            // before the number, as a sale reads where it sells from.
            $location = $this->holdingLocation($header);

            $reference = $this->numbers->next('documents.sales_order_prefix', $today);

            $order = new SalesOrder([
                'reference' => $reference,
                'location_id' => $location->id,
                'customer_id' => $header['customer_id'],
                'attention' => $header['attention'] ?? null,
                'order_date' => $today,
                'reserve_until' => $header['reserve_until'],
                'status' => SalesOrderStatus::Pending,
                'subtotal' => $totals['subtotal'] / 100,
                'discount' => $totals['discount'] / 100,
                'mobilization' => $totals['mobilization'] / 100,
                'total_amount' => $totals['total'] / 100,
                'notes' => $header['notes'] ?? null,
                'idempotency_key' => $header['idempotency_key'] ?? null,
                'created_by' => $by->id,
                'quotation_id' => $quotation?->id,
            ]);

            $this->audit->withoutAuditing(fn () => $order->save());

            foreach ($priced as $line) {
                SalesOrderLine::create(['sales_order_id' => $order->id] + $line);
            }

            $order->auditCustom(
                AuditAction::Create,
                "Took sales order [{$reference}]: ".count($priced).' line(s), '
                    .number_format($totals['total'] / 100, 2)
                    .($quotation ? ", from quotation [{$quotation->reference}]." : '.'),
                ['lines' => count($priced), 'total_amount' => $totals['total'] / 100, 'quotation' => $quotation?->reference],
            );

            if ($quotation) {
                $this->quotations->acceptAsOrder($quotation, $order);
            }

            if (! empty($header['approve'])) {
                $this->reserve($order, $by, Carbon::parse($header['reserve_until']));
            }

            $this->refreshTheFigures();

            return $order->fresh(['lines', 'customer']);
        }, attempts: 3);
    }

    /** Approve a pending order: its stock is held until the date given. */
    public function approve(SalesOrder $order, User $by, Carbon $reserveUntil): SalesOrder
    {
        return DB::transaction(function () use ($order, $by, $reserveUntil) {
            $this->reserve($order, $by, $reserveUntil);
            $this->refreshTheFigures();

            return $order->fresh();
        }, attempts: 3);
    }

    /**
     * Cancel a pending order, or release an approved one: its stock is free
     * again at once. Always with a reason.
     */
    public function cancel(SalesOrder $order, User $by, string $reason): SalesOrder
    {
        return DB::transaction(function () use ($order, $by, $reason) {
            $locked = SalesOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw SalesOrderException::notOpen($locked->reference, strtolower($locked->standing()['label']));
            }

            $wasHolding = $locked->isReserving();

            $this->audit->withoutAuditing(fn () => $locked->forceFill([
                'status' => SalesOrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $reason,
            ])->save());

            $locked->auditCustom(
                AuditAction::Cancel,
                ($wasHolding ? 'Released' : 'Cancelled')." sales order [{$locked->reference}]: {$reason}",
                ['reason' => $reason, 'released' => $wasHolding],
            );

            $this->refreshTheFigures();

            return $locked->fresh();
        }, attempts: 3);
    }

    /**
     * The order a sale is being made from, locked and checked: approved
     * (within its date or not — past it, it simply holds nothing) and for
     * this customer. Called by `SalePoster` before anything else is locked.
     */
    public function lockedForSale(int $orderId, int $customerId): SalesOrder
    {
        $order = SalesOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();

        if (! $order->isApproved()) {
            throw SalesOrderException::notApproved($order->reference, strtolower($order->standing()['label']));
        }

        if ((int) $order->customer_id !== $customerId) {
            throw SalesOrderException::otherCustomer($order->reference);
        }

        return $order;
    }

    /** Mark a locked order fulfilled: it became this sale, and holds nothing now. */
    public function fulfil(SalesOrder $order, Sale $sale): void
    {
        $this->audit->withoutAuditing(fn () => $order->forceFill([
            'status' => SalesOrderStatus::Fulfilled,
            'sale_id' => $sale->id,
            'fulfilled_at' => now(),
        ])->save());

        $order->auditCustom(
            AuditAction::Close,
            "Fulfilled sales order [{$order->reference}]: it became sale [{$sale->reference}].",
            ['sale' => $sale->reference],
        );
    }

    /**
     * Hold an order's stock: products first, then the order, then what
     * others hold — refused whole when any line is not free.
     */
    protected function reserve(SalesOrder $order, User $by, Carbon $reserveUntil): void
    {
        if ($reserveUntil->copy()->startOfDay()->lt(Carbon::today())) {
            throw SalesOrderException::dateGone();
        }

        // The lines never change, so a plain read finds the products.
        $needed = [];

        foreach (SalesOrderLine::where('sales_order_id', $order->getKey())->get(['product_id', 'quantity']) as $line) {
            $needed[$line->product_id] = ($needed[$line->product_id] ?? 0) + Quantity::toMilli($line->quantity);
        }

        ksort($needed);

        $products = Product::query()
            ->whereIn('id', array_keys($needed))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'sku', 'on_hand', 'unit_id'])
            ->load('unit:id,code')
            ->keyBy('id');

        $locked = SalesOrder::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

        if (! $locked->isPending()) {
            throw SalesOrderException::notPending($locked->reference, strtolower($locked->standing()['label']));
        }

        // The stock is held at the order's own place (phase 31.4). A place
        // switched off since cannot hold anything new.
        $location = Location::find($locked->location_id);

        if (! $location || ! $location->is_active) {
            throw new SalesOrderException(
                "Order [{$locked->reference}] holds its stock at ".($location?->name ?? 'a location').', which has been switched off. Cancel it and take the order again at another location.'
            );
        }

        // What is there and what others hold there, read with a shared lock
        // after the product rows — the order the ledger takes them in.
        $there = Reservations::atLocation(array_keys($needed), $location->id, locking: true);
        $held = Reservations::reserved(array_keys($needed), $locked->id, locking: true, locationId: $location->id);
        $short = [];

        foreach ($needed as $productId => $milli) {
            $product = $products[$productId];
            $free = ($there[$productId] ?? 0) - ($held[$productId] ?? 0);

            if ($milli > $free) {
                $short[] = "[{$product->sku}] needs ".Quantity::format(Quantity::fromMilli($milli))
                    .', only '.Quantity::format(Quantity::fromMilli(max(0, $free))).' '.($product->unit?->code ?? '')." free at {$location->name}";
            }
        }

        if ($short !== []) {
            throw SalesOrderException::notEnoughFree($short);
        }

        $this->audit->withoutAuditing(fn () => $locked->forceFill([
            'status' => SalesOrderStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $by->id,
            'reserve_until' => $reserveUntil->toDateString(),
        ])->save());

        $locked->auditCustom(
            AuditAction::Approve,
            ($locked->created_by !== null && (int) $locked->created_by === (int) $by->id ? 'Approved their own' : 'Approved')
                ." sales order [{$locked->reference}]: stock reserved until ".$reserveUntil->format('d M Y').'.',
            ['reserve_until' => $reserveUntil->toDateString()],
        );
    }

    /**
     * Where an order holds its stock (phase 31.4): the location sent, or the
     * default for a form that sent none. Read with a shared lock, so it
     * cannot be switched off between the form's check and the save.
     *
     * @param  array<string, mixed>  $header
     */
    protected function holdingLocation(array $header): Location
    {
        if (empty($header['location_id'])) {
            return Location::default() ?? throw new SalesOrderException(InventoryException::noDefaultLocation()->getMessage());
        }

        $location = Location::whereKey($header['location_id'])->sharedLock()->first();

        if (! $location || ! $location->is_active) {
            throw new SalesOrderException('The location picked for this order has been switched off. Pick another one.');
        }

        return $location;
    }

    /**
     * Every line as it is stored: what was typed, and what that is in the
     * product's own unit.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    protected function price(array $lines): array
    {
        $converted = UnitConversion::resolve($lines);
        $priced = [];

        foreach ($lines as $index => $line) {
            $unit = $converted[$index];

            if ($unit['error'] !== null || $unit['base'] === null || $unit['base'] <= 0) {
                throw SalesOrderException::cannotConvert(
                    $unit['error'] ?? 'A line could not be read in its product\'s own unit.'
                );
            }

            $typed = Quantity::normalize($line['quantity']);
            $price = round((float) $line['unit_price'], 2);

            $priced[] = [
                'product_id' => (int) $line['product_id'],
                'unit_id' => $unit['unit_id'],
                'unit_factor' => $unit['factor'],
                'unit_quantity' => $typed,
                'quantity' => Quantity::toDb($unit['base']),
                'unit_price' => $price,
                'line_total' => Quantity::lineTotal($typed, $price),
            ];
        }

        return $priced;
    }

    /**
     * What is reserved changes what the Stock screen and the selling forms
     * call available; flush the cached figures once committed.
     */
    protected function refreshTheFigures(): void
    {
        DB::afterCommit(fn () => app(InventoryMetrics::class)->flush());
    }
}
