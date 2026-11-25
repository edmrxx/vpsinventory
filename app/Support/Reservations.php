<?php

namespace App\Support;

use App\Enums\LossReportStatus;
use App\Enums\PendingPaymentStatus;
use App\Enums\SalesOrderStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What stock is held for customers, and so what is free to sell (phase 29).
 *
 * The only place a reservation is worked out. It is never stored on the
 * product: it is always the sum of the lines of approved sales orders still
 * within their `reserve_until`, so it cannot drift from the orders, and it
 * lapses at midnight with nothing to run.
 *
 * Available = on hand − reserved, never below nothing. A sale may not take
 * stock reserved for somebody else; `SalePoster` checks after the ledger has
 * moved it, with the product rows already locked.
 *
 * Since phase 31.4 an order holds its stock **at a location**
 * (`sales_orders.location_id`), and a sale at one place is weighed only
 * against what is held there: a Main Store reservation never blocks a
 * Warehouse sale. Asked with no location, it is the product-wide figure.
 *
 * Since phase 33 a **pending loss report** holds its goods aside at its
 * location too, until it is approved, rejected or withdrawn — with no date to
 * lapse. `reserved()` adds those in, so every sale, transfer, till and
 * customer order already refuses them; `heldForLosses()` reads them alone,
 * so a refusal can say which is which.
 *
 * Since CR-2 (24 November 2026) a **pending payment** — an order the kiosk
 * sent to the cashier — holds its goods at its location until `hold_until`,
 * the day it was sent by default, with nothing to run at closing. The sale
 * that pays it may take its own (`exceptPending`), as the sale fulfilling a
 * sales order takes its own; `heldForPendingPayments()` reads them alone.
 */
final class Reservations
{
    /**
     * Thousandths reserved, by product id.
     *
     * @param  list<int>|null  $productIds  Only these; null for every product.
     * @param  int|null  $exceptOrder  Leave this order's own reservation out:
     *                                 the sale that fulfils it may take it.
     * @param  bool  $locking  A shared lock, for a poster holding the product
     *                         rows: a plain read in a transaction can see a
     *                         snapshot from before an approval it waited for.
     * @param  int|null  $locationId  Only what is held at this location; null
     *                                for every location together.
     * @param  int|null  $exceptPending  Leave this pending payment's own hold
     *                                   out: the sale that pays it may take it.
     *                                   Apart from `$exceptOrder`, because the
     *                                   ids come from different tables.
     * @return array<int, int>
     */
    public static function reserved(?array $productIds = null, ?int $exceptOrder = null, bool $locking = false, ?Carbon $today = null, ?int $locationId = null, ?int $exceptPending = null): array
    {
        if ($productIds === []) {
            return [];
        }

        $held = self::forOrders($productIds, $exceptOrder, $locking, $today, $locationId);

        foreach (self::heldForLosses($productIds, $locationId, $locking) as $productId => $milli) {
            $held[$productId] = ($held[$productId] ?? 0) + $milli;
        }

        foreach (self::heldForPendingPayments($productIds, $locationId, $locking, $today, $exceptPending) as $productId => $milli) {
            $held[$productId] = ($held[$productId] ?? 0) + $milli;
        }

        return $held;
    }

    /**
     * Thousandths held by pending payments still within their date, by
     * product id (CR-2): what the kiosk sent to the cashier.
     *
     * @param  list<int>|null  $productIds
     * @return array<int, int>
     */
    public static function heldForPendingPayments(?array $productIds = null, ?int $locationId = null, bool $locking = false, ?Carbon $today = null, ?int $exceptPending = null): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('pending_payment_lines')
            ->join('pending_payments', 'pending_payments.id', '=', 'pending_payment_lines.pending_payment_id')
            ->where('pending_payments.status', PendingPaymentStatus::Pending->value)
            ->where('pending_payments.hold_until', '>=', ($today ?? Carbon::today())->toDateString())
            ->when($exceptPending, fn ($q, $id) => $q->where('pending_payments.id', '!=', $id))
            ->when($locationId, fn ($q, $id) => $q->where('pending_payments.location_id', $id))
            ->when($productIds !== null, fn ($q) => $q->whereIn('pending_payment_lines.product_id', $productIds))
            ->when($locking, fn ($q) => $q->sharedLock())
            ->groupBy('pending_payment_lines.product_id')
            ->selectRaw('pending_payment_lines.product_id AS product_id, SUM(pending_payment_lines.quantity) AS held')
            ->pluck('held', 'product_id')
            ->map(fn ($sum) => Quantity::toMilli($sum))
            ->all();
    }

    /**
     * Thousandths held aside by pending loss reports, by product id (phase
     * 33). No date: a report holds until somebody answers it.
     *
     * @param  list<int>|null  $productIds
     * @return array<int, int>
     */
    public static function heldForLosses(?array $productIds = null, ?int $locationId = null, bool $locking = false): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('loss_report_lines')
            ->join('loss_reports', 'loss_reports.id', '=', 'loss_report_lines.loss_report_id')
            ->where('loss_reports.status', LossReportStatus::Pending->value)
            ->when($locationId, fn ($q, $id) => $q->where('loss_reports.location_id', $id))
            ->when($productIds !== null, fn ($q) => $q->whereIn('loss_report_lines.product_id', $productIds))
            ->when($locking, fn ($q) => $q->sharedLock())
            ->groupBy('loss_report_lines.product_id')
            ->selectRaw('loss_report_lines.product_id AS product_id, SUM(loss_report_lines.quantity) AS held')
            ->pluck('held', 'product_id')
            ->map(fn ($sum) => Quantity::toMilli($sum))
            ->all();
    }

    /**
     * What is held, said for a person: "7 reserved for customer orders and
     * 2 held for a loss report". Empty when nothing is.
     */
    public static function describe(int $ordersMilli, int $lossesMilli, string $unit = '', int $pendingMilli = 0): string
    {
        $parts = [];

        if ($ordersMilli > 0) {
            $parts[] = Quantity::format(Quantity::fromMilli($ordersMilli)).$unit.' reserved for customer orders';
        }

        if ($pendingMilli > 0) {
            $parts[] = Quantity::format(Quantity::fromMilli($pendingMilli)).$unit.' waiting at the cashier';
        }

        if ($lossesMilli > 0) {
            $parts[] = Quantity::format(Quantity::fromMilli($lossesMilli)).$unit.' held for a loss report';
        }

        return implode(' and ', $parts);
    }

    /**
     * Thousandths reserved by approved sales orders within their date, by
     * product id: the phase 29 figure alone.
     *
     * @param  list<int>|null  $productIds
     * @return array<int, int>
     */
    protected static function forOrders(?array $productIds, ?int $exceptOrder, bool $locking, ?Carbon $today, ?int $locationId): array
    {
        return DB::table('sales_order_lines')
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->where('sales_orders.status', SalesOrderStatus::Approved->value)
            ->where('sales_orders.reserve_until', '>=', ($today ?? Carbon::today())->toDateString())
            ->when($exceptOrder, fn ($q, $id) => $q->where('sales_orders.id', '!=', $id))
            ->when($locationId, fn ($q, $id) => $q->where('sales_orders.location_id', $id))
            ->when($productIds !== null, fn ($q) => $q->whereIn('sales_order_lines.product_id', $productIds))
            ->when($locking, fn ($q) => $q->sharedLock())
            ->groupBy('sales_order_lines.product_id')
            ->selectRaw('sales_order_lines.product_id AS product_id, SUM(sales_order_lines.quantity) AS reserved')
            ->pluck('reserved', 'product_id')
            ->map(fn ($sum) => Quantity::toMilli($sum))
            ->all();
    }

    /**
     * Thousandths each product has at one location, by product id (phase
     * 31.4). A product with no row there has nothing there.
     *
     * @param  list<int>|null  $productIds  Only these; null for every product.
     * @param  bool  $locking  A shared lock, for a poster that already holds
     *                         the product rows — the ledger writes these rows
     *                         after locking the products, so the order is
     *                         the same.
     * @return array<int, int>
     */
    public static function atLocation(?array $productIds, int $locationId, bool $locking = false): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('location_stocks')
            ->where('location_id', $locationId)
            ->when($productIds !== null, fn ($q) => $q->whereIn('product_id', $productIds))
            ->when($locking, fn ($q) => $q->sharedLock())
            ->pluck('on_hand', 'product_id')
            ->map(fn ($onHand) => Quantity::toMilli($onHand))
            ->all();
    }

    /**
     * What may be sold of a product: on hand less what is held for others,
     * never below nothing.
     */
    public static function available(int|float|string|null $onHand, int $reservedMilli): int|float
    {
        return Quantity::fromMilli(max(0, Quantity::toMilli($onHand) - $reservedMilli));
    }
}
