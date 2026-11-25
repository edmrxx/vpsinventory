<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;

/**
 * Weighs a delivery against the order it fills (phase 23).
 *
 * The only place these rules live. The purchase form asks, so each error
 * lands under the line that caused it; `PurchasePoster` asks again with the
 * order locked, and decides.
 *
 * The rules:
 * - the order is approved and still waiting for goods (open or partly
 *   received — one waiting for approval is not, phase 24.4), and the
 *   delivery comes from the order's own supplier;
 * - a line tied to an order line is that line's product, and all the lines
 *   tied to one order line bring in no more than is still to come on it;
 * - at least one line is tied to the order — otherwise it is not a delivery
 *   against it;
 * - a line not tied to the order is allowed: goods the order did not ask
 *   for, shown as such.
 *
 * A cost different from the one agreed is not refused. It is what the
 * supplier invoiced, and the order and the delivery both show the change.
 */
class OrderReceiving
{
    /**
     * @param  PurchaseOrder  $order  With `lines` loaded.
     * @param  array<int, array{order_line_id:?int, product_id:int, milli:?int}>  $rows  Keyed by line index; `milli` in the product's own unit.
     * @return array{errors: array<string, string>, received: array<int, int>} `received`: order line id => thousandths this delivery brings in.
     */
    public static function weigh(PurchaseOrder $order, int $supplierId, array $rows): array
    {
        $errors = [];
        $received = [];

        if ($order->isPendingApproval()) {
            $errors['purchase_order_id'] = "Order [{$order->reference}] is waiting for approval, so nothing can be received against it yet.";

            return ['errors' => $errors, 'received' => []];
        }

        if (! $order->isOutstanding()) {
            $errors['purchase_order_id'] = "Order [{$order->reference}] is "
                .strtolower($order->status->label()).', so nothing more can be received against it.';

            return ['errors' => $errors, 'received' => []];
        }

        if ((int) $order->supplier_id !== $supplierId) {
            $errors['supplier_id'] = "Order [{$order->reference}] was placed with another supplier.";

            return ['errors' => $errors, 'received' => []];
        }

        $lines = $order->lines->keyBy('id');
        $lastIndex = [];

        foreach ($rows as $index => $row) {
            $id = $row['order_line_id'];

            if ($id === null) {
                continue;
            }

            $line = $lines[$id] ?? null;

            if ($line === null) {
                $errors["lines.{$index}.product_id"] = "This line is not on order [{$order->reference}].";

                continue;
            }

            if ((int) $line->product_id !== (int) $row['product_id']) {
                $errors["lines.{$index}.product_id"] = 'This line was ordered as another product. Post a different product as a line of its own.';

                continue;
            }

            $received[$id] = ($received[$id] ?? 0) + (int) ($row['milli'] ?? 0);
            $lastIndex[$id] = $index;
        }

        foreach ($received as $id => $milli) {
            $line = $lines[$id];
            $left = $line->outstandingMilli();

            if ($milli > $left) {
                $unit = $line->product?->unit?->code;

                $errors["lines.{$lastIndex[$id]}.quantity"] = 'Only '.Quantity::format(Quantity::fromMilli($left))
                    .($unit ? " {$unit}" : '')." is still to come on order [{$order->reference}]. "
                    .'Post anything extra as a line of its own.';
            }
        }

        if ($errors === [] && $received === []) {
            $errors['lines'] = "None of these lines is on order [{$order->reference}].";
        }

        return ['errors' => $errors, 'received' => $errors === [] ? $received : []];
    }

    /**
     * Every order line whose received figure is not what its posted
     * deliveries add up to. `inventory:verify` runs this; an empty list is
     * the answer it wants.
     *
     * @return list<array{order:string, sku:?string, received:int|float, delivered:int|float}>
     */
    public static function reconcile(): array
    {
        $delivered = DB::table('purchase_lines')
            ->join('purchases', 'purchases.id', '=', 'purchase_lines.purchase_id')
            ->where('purchases.status', DocumentStatus::Posted->value)
            ->whereNotNull('purchase_lines.purchase_order_line_id')
            ->groupBy('purchase_lines.purchase_order_line_id')
            ->selectRaw('purchase_lines.purchase_order_line_id AS line_id, SUM(purchase_lines.quantity) AS delivered')
            ->pluck('delivered', 'line_id');

        $wrong = [];

        DB::table('purchase_order_lines')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_lines.purchase_order_id')
            ->leftJoin('products', 'products.id', '=', 'purchase_order_lines.product_id')
            ->orderBy('purchase_order_lines.id')
            ->select('purchase_order_lines.id', 'purchase_order_lines.received_quantity', 'purchase_orders.reference', 'products.sku')
            ->chunk(500, function ($lines) use ($delivered, &$wrong) {
                foreach ($lines as $line) {
                    $has = Quantity::toMilli($line->received_quantity);
                    $should = Quantity::toMilli($delivered[$line->id] ?? 0);

                    if ($has !== $should) {
                        $wrong[] = [
                            'order' => $line->reference,
                            'sku' => $line->sku,
                            'received' => Quantity::fromMilli($has),
                            'delivered' => Quantity::fromMilli($should),
                        ];
                    }
                }
            });

        return $wrong;
    }

    /**
     * Where an order stands, from what has come in against it.
     *
     * Waiting for approval, rejected, closed and cancelled are decisions
     * somebody made, and stay. Otherwise
     * every line in full is received, anything in is partly received, and
     * nothing in is open.
     */
    public static function statusOf(PurchaseOrder $order): PurchaseOrderStatus
    {
        // Decisions somebody made: approval (phase 24.4), closing short and
        // cancelling. None of them is undone by what came in.
        if (in_array($order->status, [
            PurchaseOrderStatus::PendingApproval,
            PurchaseOrderStatus::Rejected,
            PurchaseOrderStatus::Closed,
            PurchaseOrderStatus::Cancelled,
        ], true)) {
            return $order->status;
        }

        $all = $order->lines->every(fn ($line) => $line->outstandingMilli() === 0);
        $any = $order->lines->contains(fn ($line) => Quantity::toMilli($line->received_quantity) > 0);

        return match (true) {
            $all && $any => PurchaseOrderStatus::Received,
            $any => PurchaseOrderStatus::PartlyReceived,
            default => PurchaseOrderStatus::Open,
        };
    }
}
