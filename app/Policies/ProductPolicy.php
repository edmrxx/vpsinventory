<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Auth\Access\Response;

class ProductPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('products.view');
    }

    public function view(User $actor, Product $product): bool
    {
        return $actor->can('products.view');
    }

    /**
     * The warehouse view: quantities and what they are worth.
     *
     * Its own permission, because reading the shelf is not the same as
     * changing what a product is.
     */
    public function viewStock(User $actor): bool
    {
        return $actor->can('stock.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('products.create');
    }

    public function update(User $actor, Product $product): bool
    {
        return $actor->can('products.update');
    }

    /**
     * A product holding stock is never deleted, because the stock would
     * vanish with no document to explain it. Nor is one that has ever moved,
     * even at zero stock: its purchases and sales still name it, and a
     * deleted row would leave them unreadable.
     *
     * Switch the product off instead. It then disappears from every new
     * document while its history stays intact.
     */
    public function delete(User $actor, Product $product): Response
    {
        if (! $actor->can('products.delete')) {
            return Response::deny('You may not delete products.');
        }

        if ($product->on_hand > 0) {
            return Response::deny(
                'This product still holds '.Quantity::format($product->on_hand).' on hand. '
                    .'Clear the stock first, or switch the product off instead.'
            );
        }

        $movements = $this->movementCount($product);

        if ($movements > 0) {
            return Response::deny(
                "This product has {$movements} movement(s) behind it. "
                    .'Deleting it would leave that history unreadable, so switch it off instead.'
            );
        }

        $requested = $this->requestLineCount($product);

        if ($requested > 0) {
            return Response::deny(
                "This product is named on {$requested} purchase request, purchase order, quotation, sales order, stock count or loss report line(s). "
                    .'Deleting it would leave them unreadable, so switch it off instead.'
            );
        }

        return Response::allow();
    }

    /**
     * How many purchase request and purchase order lines name this product.
     *
     * Loaded with `withCount` on the list, like the movements, so a page of
     * rows asks nothing extra.
     */
    protected function requestLineCount(Product $product): int
    {
        return (int) ($product->purchase_request_lines_count ?? $product->purchaseRequestLines()->count())
            + (int) ($product->purchase_order_lines_count ?? $product->purchaseOrderLines()->count())
            + (int) ($product->quotation_lines_count ?? $product->quotationLines()->count())
            + (int) ($product->sales_order_lines_count ?? $product->salesOrderLines()->count())
            + (int) ($product->stock_count_lines_count ?? $product->stockCountLines()->count())
            + (int) ($product->loss_report_lines_count ?? $product->lossReportLines()->count())
            + (int) ($product->pending_payment_lines_count ?? $product->pendingPaymentLines()->count());
    }

    /**
     * How many times this product has moved.
     *
     * The list screen loads the figure with `withCount`, so a page of rows
     * asks the database nothing extra. Only a single-record route falls back
     * to a query.
     */
    protected function movementCount(Product $product): int
    {
        return (int) ($product->movements_count ?? $product->movements()->count());
    }

    public function updateStatus(User $actor, Product $product): bool
    {
        return $actor->can('products.status');
    }
}
