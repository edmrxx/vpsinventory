<?php

namespace App\Support;

use App\Enums\LossReportStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\PurchaseRequestStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StockCountStatus;
use App\Models\PendingPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What waits on this person (24 November 2026, on the user's GO!): the red
 * counts on the sidebar, and the same list under the bell.
 *
 * The only place they are worked out. Each counts **what this person can
 * act on**: an approver, everything waiting for approval; anybody else,
 * their own still waiting — so neither the badge nor the bell nags somebody
 * who can do nothing about it. A screen the person cannot open is left out.
 * `for()` gives the counts by the item's `badge` key in
 * `resources/js/navigation.js` (read by `NavLink.vue`); `waiting()` gives
 * each with its words and a link to the list narrowed to exactly those
 * (read by `AppTopbar.vue`).
 *
 * One indexed count per item the person can see, shared lazily with every
 * page by `HandleInertiaRequests`, and read once for both.
 *
 * Not counted on purpose: Quotations (an open one waits on the customer,
 * not on staff), and the overdue screens — Collectibles, Aging, Payables —
 * which are warnings the dashboard already shows, not steps waiting.
 */
final class SidebarBadges
{
    /**
     * The counts, by navigation `badge` key.
     *
     * @return array<string, int>
     */
    public static function for(?User $user): array
    {
        return array_column(self::waiting($user), 'count', 'key');
    }

    /**
     * Everything this person can see waiting, zero counts included, in the
     * sidebar's order.
     *
     * `text` is the words after the count, as [one, many].
     *
     * @return list<array{key:string, count:int, text:array{0:string, 1:string}, href:string}>
     */
    public static function waiting(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $items = [];

        // A request waiting for its answer.
        if ($user->can('purchase_requests.view')) {
            $all = $user->can('purchase_requests.approve');
            $items[] = self::item(
                'purchase_requests', 'purchase_requests', PurchaseRequestStatus::Pending->value,
                $all ? null : ['requested_by', $user->id],
                $all ? ['purchase request waiting for your approval', 'purchase requests waiting for your approval'] : ['of your purchase requests still waiting for approval', 'of your purchase requests still waiting for approval'],
                '/purchase-requests',
            );
        }

        // An order waiting for approval before it is sent (phase 24.4).
        if ($user->can('purchase_orders.view')) {
            $all = $user->can('purchase_orders.approve');
            $items[] = self::item(
                'purchase_orders', 'purchase_orders', PurchaseOrderStatus::PendingApproval->value,
                $all ? null : ['ordered_by', $user->id],
                $all ? ['purchase order waiting for your approval', 'purchase orders waiting for your approval'] : ['of your purchase orders still waiting for approval', 'of your purchase orders still waiting for approval'],
                '/purchase-orders',
            );
        }

        // A customer order not yet approved, so holding nothing yet.
        if ($user->can('sales_orders.view')) {
            $all = $user->can('sales_orders.approve');
            $items[] = self::item(
                'sales_orders', 'sales_orders', SalesOrderStatus::Pending->value,
                $all ? null : ['created_by', $user->id],
                $all ? ['sales order waiting for your approval', 'sales orders waiting for your approval'] : ['of your sales orders still waiting for approval', 'of your sales orders still waiting for approval'],
                '/sales-orders',
            );
        }

        // Orders the kiosk sent to the cashier (CR-2): all of them, for
        // whoever may open the list.
        if ($user->can('pending_payments.view')) {
            $items[] = [
                'key' => 'pending_payments',
                'count' => PendingPayment::query()->waiting()->count(),
                'text' => ['order from the kiosk waiting to be paid', 'orders from the kiosk waiting to be paid'],
                'href' => '/pending-payments?status=waiting',
            ];
        }

        // A count handed in for approval; for a counter, their own sheet
        // still being counted.
        if ($user->can('stock_counts.view')) {
            $items[] = $user->can('stock_counts.approve')
                ? self::item('stock_counts', 'stock_counts', StockCountStatus::Submitted->value, null,
                    ['stock count handed in for your approval', 'stock counts handed in for your approval'], '/stock-counts')
                : self::item('stock_counts', 'stock_counts', StockCountStatus::Counting->value, ['started_by', $user->id],
                    ['count sheet of yours still being counted', 'count sheets of yours still being counted'], '/stock-counts');
        }

        // A loss report waiting to be written off or rejected.
        if ($user->can('losses.view')) {
            $all = $user->can('losses.approve');
            $items[] = self::item(
                'loss_reports', 'loss_reports', LossReportStatus::Pending->value,
                $all ? null : ['reported_by', $user->id],
                $all ? ['loss report waiting for your approval', 'loss reports waiting for your approval'] : ['of your loss reports still waiting for approval', 'of your loss reports still waiting for approval'],
                '/loss-reports',
            );
        }

        return $items;
    }

    /**
     * One count, with the list it links to narrowed to the same rows.
     *
     * @param  array{0:string, 1:int}|null  $own  [column, user id], or null for everybody's.
     * @param  array{0:string, 1:string}  $text  The words after the count, as [one, many].
     * @return array{key:string, count:int, text:array{0:string, 1:string}, href:string}
     */
    protected static function item(string $key, string $table, string $status, ?array $own, array $text, string $list): array
    {
        $query = ['status' => $status] + ($own ? [$own[0] => $own[1]] : []);

        return [
            'key' => $key,
            'count' => DB::table($table)
                ->where('status', $status)
                ->when($own, fn ($q) => $q->where($own[0], $own[1]))
                ->count(),
            'text' => $text,
            'href' => $list.'?'.http_build_query($query),
        ];
    }
}
