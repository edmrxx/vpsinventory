<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\PendingPaymentStatus;
use App\Exceptions\PendingPaymentException;
use App\Models\Location;
use App\Models\PendingPayment;
use App\Models\PendingPaymentLine;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\Quantity;
use App\Support\Reservations;
use App\Support\SaleTotals;
use App\Support\UnitConversion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pending payment: the kiosk takes the order, the cashier takes the money
 * (CR-2, the client's meeting of 24 November 2026). The only place one is
 * sent, cancelled or marked paid.
 *
 * Nothing moves on the shelf here: the stock leaves when the cashier's sale
 * posts. What it decides is what is held. Sending locks the products the
 * order names, in ascending id order, then reads the location with a shared
 * lock and what is free there with locking reads — the order
 * `SalesOrderDesk::reserve()` takes them in — and refuses the whole order
 * when any line is not free. Only then does it take the number.
 *
 * Paying is done inside the sale's own transaction by `SalePoster`, which
 * asks `lockedForSale()` here first and `markPaid()` once it has posted.
 */
class PendingPaymentDesk
{
    public function __construct(
        protected DocumentNumber $numbers,
        protected AuditService $audit,
    ) {}

    /**
     * Send an order to the cashier. Its goods are held from now until
     * closing on the last day of `documents.pending_payment_hold_days`.
     *
     * @param  array{customer_id:int, location_id:int, discount?:float|string|null, note?:string|null, idempotency_key?:string|null}  $header
     * @param  list<array{product_id:int, unit_id?:int|null, quantity:int|float|string, unit_price:float|string}>  $lines
     */
    public function send(array $header, array $lines, User $by): PendingPayment
    {
        $lines = array_values($lines);
        $today = Carbon::today();

        return DB::transaction(function () use ($header, $lines, $today, $by) {
            $priced = $this->price($lines);
            $totals = SaleTotals::of($lines, $header['discount'] ?? 0);

            $needed = [];

            foreach ($priced as $line) {
                $needed[$line['product_id']] = ($needed[$line['product_id']] ?? 0) + Quantity::toMilli($line['quantity']);
            }

            ksort($needed);

            // The product rows first, in ascending id order: the one lock a
            // sale, a transfer and an order approval all take first too.
            $products = Product::query()
                ->whereIn('id', array_keys($needed))
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'sku', 'unit_id'])
                ->load('unit:id,code')
                ->keyBy('id');

            $location = Location::whereKey($header['location_id'])->sharedLock()->first();

            if (! $location || ! $location->is_active) {
                throw new PendingPaymentException('This till\'s location has been switched off. Pick another store.');
            }

            // What is there and what others hold there, read with a shared
            // lock after the product rows.
            $there = Reservations::atLocation(array_keys($needed), $location->id, locking: true);
            $held = Reservations::reserved(array_keys($needed), locking: true, locationId: $location->id);
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
                throw PendingPaymentException::notEnoughFree($short);
            }

            $reference = $this->numbers->next('documents.pending_payment_prefix', $today);

            $pending = new PendingPayment([
                'reference' => $reference,
                'location_id' => $location->id,
                'customer_id' => $header['customer_id'],
                'order_date' => $today,
                'hold_until' => $today->copy()->addDays(self::holdDays()),
                'status' => PendingPaymentStatus::Pending,
                'subtotal' => $totals['subtotal'] / 100,
                'discount' => $totals['discount'] / 100,
                'total_amount' => $totals['total'] / 100,
                'note' => $header['note'] ?? null,
                'idempotency_key' => $header['idempotency_key'] ?? null,
                'taken_by' => $by->id,
            ]);

            $this->audit->withoutAuditing(fn () => $pending->save());

            foreach ($priced as $line) {
                PendingPaymentLine::create(['pending_payment_id' => $pending->id] + $line);
            }

            $pending->auditCustom(
                AuditAction::Create,
                "Sent order [{$reference}] to the cashier: ".count($priced).' line(s), '
                    .number_format($totals['total'] / 100, 2).", held at {$location->name}.",
                ['lines' => count($priced), 'total_amount' => $totals['total'] / 100, 'location' => $location->name],
            );

            $this->refreshTheFigures();

            return $pending->fresh(['lines', 'customer', 'location']);
        }, attempts: 3);
    }

    /** Cancel one still waiting: the customer did not pay. Its goods are free at once. */
    public function cancel(PendingPayment $pending, User $by, string $reason): PendingPayment
    {
        return DB::transaction(function () use ($pending, $by, $reason) {
            $locked = PendingPayment::whereKey($pending->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw PendingPaymentException::notOpen($locked->reference, $locked->standing()['label']);
            }

            $this->audit->withoutAuditing(fn () => $locked->forceFill([
                'status' => PendingPaymentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $reason,
            ])->save());

            $locked->auditCustom(
                AuditAction::Cancel,
                ((int) $locked->taken_by === (int) $by->id ? 'Cancelled their own' : 'Cancelled')
                    ." pending payment [{$locked->reference}]: {$reason}",
                ['reason' => $reason],
            );

            $this->refreshTheFigures();

            return $locked->fresh();
        }, attempts: 3);
    }

    /**
     * The pending payment a sale is paying, locked and checked: still
     * waiting, for this customer, at this place, and for exactly the lines,
     * prices and discount it was sent with. Called by `SalePoster` before
     * anything else is locked, so two cashiers cannot both take the money.
     *
     * @param  list<array<string, mixed>>  $lines  As sent with the sale.
     * @param  array<int, array{unit_id:?int}>  $converted  From `UnitConversion::resolve()`.
     */
    public function lockedForSale(int $id, int $customerId, ?int $locationId, array $lines, array $converted, float|string|null $discount): PendingPayment
    {
        $pending = PendingPayment::whereKey($id)->lockForUpdate()->firstOrFail();

        if (! $pending->isWaiting()) {
            throw PendingPaymentException::notWaiting($pending->reference, $pending->standing()['label']);
        }

        if ((int) $pending->customer_id !== $customerId) {
            throw PendingPaymentException::otherCustomer($pending->reference);
        }

        if ($locationId !== null && (int) $pending->location_id !== $locationId) {
            throw PendingPaymentException::otherPlace($pending->reference, (string) $pending->location?->name);
        }

        if (! self::sameLines($pending, $lines, $converted, $discount)) {
            throw PendingPaymentException::linesChanged($pending->reference);
        }

        return $pending;
    }

    /** Mark a locked pending payment paid: it became this sale, and holds nothing now. */
    public function markPaid(PendingPayment $pending, Sale $sale): void
    {
        $this->audit->withoutAuditing(fn () => $pending->forceFill([
            'status' => PendingPaymentStatus::Paid,
            'sale_id' => $sale->id,
            'paid_at' => now(),
        ])->save());

        $pending->auditCustom(
            AuditAction::Close,
            "Paid pending payment [{$pending->reference}]: it became sale [{$sale->reference}].",
            ['sale' => $sale->reference],
        );
    }

    /**
     * Are these the lines it was sent with? Compared as typed — product,
     * unit, quantity and price per unit, in any order — and the discount to
     * the centavo. The customer was shown these prices; nothing is re-read.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<int, array{unit_id:?int}>  $converted
     */
    public static function sameLines(PendingPayment $pending, array $lines, array $converted, float|string|null $discount): bool
    {
        if (Quantity::centavos(round((float) ($discount ?? 0), 2)) !== Quantity::centavos((float) $pending->discount)) {
            return false;
        }

        $sent = [];

        foreach (array_values($lines) as $index => $line) {
            $sent[] = implode(':', [
                (int) ($line['product_id'] ?? 0),
                (int) ($converted[$index]['unit_id'] ?? 0),
                Quantity::toMilli($line['quantity'] ?? 0),
                Quantity::centavos(round((float) ($line['unit_price'] ?? 0), 2)),
            ]);
        }

        $stored = $pending->lines()
            ->get(['product_id', 'unit_id', 'unit_quantity', 'unit_price'])
            ->map(fn (PendingPaymentLine $line) => implode(':', [
                (int) $line->product_id,
                (int) $line->unit_id,
                Quantity::toMilli($line->unit_quantity),
                Quantity::centavos((float) $line->unit_price),
            ]))
            ->all();

        sort($sent);
        sort($stored);

        return $sent === $stored;
    }

    /** Whole days a pending payment holds its goods after the day it is sent. */
    public static function holdDays(): int
    {
        return max(0, min(7, (int) settings('documents.pending_payment_hold_days')));
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
                throw new PendingPaymentException(
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
     * What is held changes what the Stock screen and the selling forms call
     * available; flush the cached figures once committed.
     */
    protected function refreshTheFigures(): void
    {
        DB::afterCommit(fn () => app(InventoryMetrics::class)->flush());
    }
}
