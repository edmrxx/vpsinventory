<?php

namespace App\Http\Controllers;

use App\Exceptions\CanvassException;
use App\Exceptions\PurchaseOrderException;
use App\Exceptions\PurchaseRequestException;
use App\Http\Requests\PurchaseRequest\AwardPurchaseRequestRequest;
use App\Http\Requests\PurchaseRequest\ReopenAwardRequest;
use App\Http\Requests\PurchaseRequest\StoreSupplierQuoteRequest;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Services\CanvassDesk;
use App\Services\PurchaseOrderDesk;
use App\Support\UnitConversion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The canvass on an approved purchase request (phase 24.2): the form a
 * supplier's prices are typed into, removing a quote, the award, and
 * reopening it. The Abstract of Quotation itself is part of the request's
 * own `detail()`, so the page and the dialog read the same table.
 */
class CanvassController extends Controller
{
    public function __construct(
        protected CanvassDesk $desk,
        protected PurchaseOrderDesk $orders,
    ) {}

    /** The form: one supplier, a price per item. */
    public function create(Request $request, PurchaseRequest $purchaseRequest): Response|RedirectResponse
    {
        $purchaseRequest->load(['purchaseOrders:id,purchase_request_id', 'department:id,name']);

        if ($request->user()->cannot('quote', $purchaseRequest)) {
            return redirect()
                ->route('purchase-requests.show', $purchaseRequest)
                ->with('error', 'Supplier quotes can be recorded only on an approved request that is not yet awarded or ordered.');
        }

        $purchaseRequest->load([
            'lines' => fn ($q) => $q->orderBy('id'),
            'lines.product:id,sku,name,unit_id',
            'lines.product.unit:id,code',
            'lines.unit:id,code',
        ]);

        $quoted = SupplierQuote::where('purchase_request_id', $purchaseRequest->id)->pluck('supplier_id')->all();

        return Inertia::render('PurchaseRequests/Quote', [
            'title' => 'Supplier quote',
            'request' => [
                'id' => $purchaseRequest->id,
                'reference' => $purchaseRequest->reference,
                'department' => $purchaseRequest->department?->name,
                'needed_by' => $purchaseRequest->needed_by?->format('d M Y'),
                'lines' => $purchaseRequest->lines->map(fn ($line) => [
                    'id' => $line->id,
                    'name' => $line->product?->name,
                    'sku' => $line->product?->sku,
                    'unit_quantity' => $line->unit_quantity,
                    'unit_code' => $line->unit?->code ?? $line->product?->unit?->code,
                    'quantity_label' => UnitConversion::describe(
                        $line->unit_quantity,
                        $line->unit?->code,
                        $line->quantity,
                        $line->product?->unit?->code,
                        $line->unit_factor,
                    ),
                ])->all(),
            ],
            // Active suppliers that have not quoted on this request yet.
            'suppliers' => Supplier::query()
                ->where('is_active', true)
                ->whereNotIn('id', $quoted)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Supplier $s) => ['value' => $s->id, 'label' => $s->name])
                ->all(),
        ]);
    }

    public function store(StoreSupplierQuoteRequest $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('quote', $purchaseRequest);

        try {
            $quote = $this->desk->recordQuote($purchaseRequest, $request->validated(), $request->user());
        } catch (CanvassException $e) {
            throw ValidationException::withMessages(['supplier_id' => $e->getMessage()]);
        }

        return redirect()
            ->route('purchase-requests.show', $purchaseRequest)
            ->with('success', "Quote from {$quote->supplier->name} recorded on [{$purchaseRequest->reference}].");
    }

    public function destroy(Request $request, PurchaseRequest $purchaseRequest, SupplierQuote $quote): RedirectResponse
    {
        abort_unless((int) $quote->purchase_request_id === (int) $purchaseRequest->id, 404);

        $this->authorize('quote', $purchaseRequest);

        try {
            $this->desk->removeQuote($purchaseRequest, $quote, $request->user());
        } catch (CanvassException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'The quote was removed.');
    }

    public function award(AwardPurchaseRequestRequest $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('award', $purchaseRequest);

        try {
            $this->desk->award(
                $purchaseRequest,
                $request->validated()['choices'],
                $request->validated()['reasons'] ?? [],
                $request->user(),
            );
        } catch (CanvassException $e) {
            throw ValidationException::withMessages($e->errors ?: ['choices' => $e->getMessage()]);
        }

        return back()->with('success', "Request [{$purchaseRequest->reference}] was awarded.");
    }

    /**
     * The orders from the award (phase 24.3): one for each winning supplier,
     * at the prices it quoted. The request closes as ordered on all of them.
     */
    public function order(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('orderFromAward', $purchaseRequest);

        try {
            $orders = $this->orders->placeFromAward($purchaseRequest, $request->user());
        } catch (PurchaseOrderException|PurchaseRequestException $e) {
            return back()->with('error', $e->getMessage());
        }

        $references = implode(', ', array_map(fn ($order) => $order->reference, $orders));

        return redirect()
            ->route('purchase-requests.show', $purchaseRequest)
            ->with('success', count($orders) === 1
                ? "Order {$references} was placed from the award."
                : count($orders)." orders were placed from the award: {$references}.");
    }

    public function reopen(ReopenAwardRequest $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('reopenAward', $purchaseRequest);

        try {
            $this->desk->reopen($purchaseRequest, $request->validated()['reason'], $request->user());
        } catch (CanvassException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "The award on [{$purchaseRequest->reference}] was reopened.");
    }
}
