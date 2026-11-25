<?php

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use App\Enums\DocumentStatus;
use App\Support\SupplierAccount;
use App\Models\SupplierPayment;
use App\Enums\AuditAction;
use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Http\Requests\Supplier\UpdateSupplierRequest;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\AuditService;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who goods are bought from.
 *
 * This is the worked example for the master modules. Customers, categories
 * and units follow the same shape, and so does the product master.
 */
class SupplierController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Supplier::class);

        $filters = $this->validatedFilters($request);

        $query = Supplier::query()
            // The delete rule asks how many purchases name this supplier.
            // Loading it here keeps the policy from querying once per row.
            ->withCount(['purchases', 'purchaseOrders'])
            ->when($filters['status'] !== null, fn ($q) => $q->where('is_active', $filters['status'] === 'active'))
            ->when(
                $filters['vat'] !== null,
                fn ($q) => $q->where('is_vat_registered', $filters['vat'] === 'registered')
            );

        $table = TableQuery::for($query, $request)
            ->searchable(['code', 'name', 'contact_person', 'email', 'phone'])
            ->sortable(['code', 'name', 'contact_person', 'is_active', 'created_at'])
            ->defaultSort('name')
            ->extra($filters);

        return Inertia::render('Suppliers/Index', [
            'title' => 'Suppliers',
            'suppliers' => $table->paginate()->through(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'code' => $supplier->code,
                'name' => $supplier->name,
                'contact_person' => $supplier->contact_person,
                'email' => $supplier->email,
                'phone' => $supplier->phone,
                'tin' => $supplier->tin,
                'is_vat_registered' => $supplier->is_vat_registered,
                'payment_terms_days' => (int) $supplier->payment_terms_days,
                'vat_label' => $supplier->vatLabel(),
                'address' => $supplier->address,
                'notes' => $supplier->notes,
                'is_active' => $supplier->is_active,
                'created_at' => $supplier->created_at?->format('d M Y'),
                'can' => [
                    'update' => $request->user()->can('update', $supplier),
                    'delete' => $request->user()->can('delete', $supplier),
                    'status' => $request->user()->can('updateStatus', $supplier),
                ],
            ]),
            'filters' => $table->state(),
            'can' => ['create' => $request->user()->can('create', Supplier::class)],
        ]);
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        $this->authorize('create', Supplier::class);

        $supplier = Supplier::create($request->validated());

        return back()->with('success', "Supplier [{$supplier->code}] was created.");
    }

    public function show(Request $request, Supplier $supplier): Response
    {
        $this->authorize('view', $supplier);

        $supplier->auditView();

        return Inertia::render('Suppliers/Show', array_merge(
            ['title' => $supplier->name],
            $this->detail($request, $supplier),
        ));
    }

    /**
     * The same supplier, as JSON, for the dialog on the list (24 November
     * 2026), so reading one does not lose the list's place.
     *
     * Both this and `show()` call `detail()`, so the dialog cannot show a
     * different figure from the page.
     */
    public function preview(Request $request, Supplier $supplier): JsonResponse
    {
        $this->authorize('view', $supplier);

        $supplier->auditView();

        return response()->json($this->detail($request, $supplier));
    }

    /**
     * Everything a supplier shows: the record, the last deliveries and, for
     * whoever may see payables, what is owed and the bills behind it.
     *
     * @return array<string, mixed>
     */
    protected function detail(Request $request, Supplier $supplier): array
    {
        return [
            'supplier' => [
                'id' => $supplier->id,
                'code' => $supplier->code,
                'name' => $supplier->name,
                'contact_person' => $supplier->contact_person,
                'email' => $supplier->email,
                'phone' => $supplier->phone,
                'tin' => $supplier->tin,
                'is_vat_registered' => $supplier->is_vat_registered,
                'payment_terms_days' => (int) $supplier->payment_terms_days,
                'vat_label' => $supplier->vatLabel(),
                'address' => $supplier->address,
                'notes' => $supplier->notes,
                'is_active' => $supplier->is_active,
                'created_at' => $supplier->created_at?->format('d M Y, g:i A'),
                'updated_at' => $supplier->updated_at?->format('d M Y, g:i A'),
            ],
            // The last handful of deliveries, so the screen says something
            // about this supplier rather than only repeating the form.
            'purchases' => $request->user()->can('viewAny', Purchase::class)
                ? $supplier->purchases()
                    ->latest('purchase_date')
                    ->latest('id')
                    ->limit(5)
                    ->get(['id', 'reference', 'purchase_date', 'total_amount', 'status'])
                    ->map(fn (Purchase $purchase) => [
                        'id' => $purchase->id,
                        'reference' => $purchase->reference,
                        'purchase_date' => $purchase->purchase_date?->format('d M Y'),
                        'total_amount' => $purchase->total_amount,
                        'status' => $purchase->status->value,
                        'status_label' => $purchase->status->label(),
                        'status_tone' => $purchase->status->tone(),
                    ])
                    ->all()
                : [],
            // What the shop owes this supplier, and the bills behind it
            // (phase 24), for whoever may see payables.
            'account' => $request->user()->can('viewAny', SupplierPayment::class)
                ? SupplierAccount::account($supplier) + [
                    'bills' => $supplier->purchases()
                        ->where('status', DocumentStatus::Posted->value)
                        ->where('balance_due', '>', 0)
                        ->orderByRaw('COALESCE(due_date, purchase_date)')
                        ->orderBy('id')
                        ->limit(10)
                        ->get(['id', 'reference', 'supplier_reference', 'purchase_date', 'due_date', 'balance_due'])
                        ->map(fn (Purchase $bill) => [
                            'id' => $bill->id,
                            'reference' => $bill->reference,
                            'supplier_reference' => $bill->supplier_reference,
                            'due_date' => $bill->due_date?->format('d M Y'),
                            'overdue' => $bill->due_date !== null && $bill->due_date->lt(Carbon::today()),
                            'balance_due' => $bill->balance_due,
                        ])
                        ->all(),
                ]
                : null,
            'can' => [
                'pay' => $request->user()->can('create', SupplierPayment::class),
                'update' => $request->user()->can('update', $supplier),
                'delete' => $request->user()->can('delete', $supplier),
            ],
        ];
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $supplier->update($request->validated());

        return back()->with('success', "Supplier [{$supplier->code}] was saved.");
    }

    public function destroy(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('delete', $supplier);

        $code = $supplier->code;
        $supplier->delete();

        return redirect()
            ->route('suppliers.index')
            ->with('success', "Supplier [{$code}] was deleted.");
    }

    /**
     * Switch a supplier on or off. Kept apart from the edit form so one
     * click is enough, and so the trail reads Activate or Deactivate rather
     * than Update.
     */
    public function updateStatus(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('updateStatus', $supplier);

        $this->audit->withoutAuditing(
            fn () => $supplier->update(['is_active' => ! $supplier->is_active])
        );

        $supplier->auditStatus($supplier->is_active);

        return back()->with(
            'success',
            $supplier->is_active
                ? "Supplier [{$supplier->code}] is active again."
                : "Supplier [{$supplier->code}] is now inactive."
        );
    }

    /**
     * The status filter above the table. Anything else in the query string
     * is refused, so the list cannot be steered from the address bar.
     *
     * @return array{status:?string}
     */
    /**
     * Switch a supplier between VAT-registered and Non-VAT.
     *
     * The same shape as `updateStatus()`, with one difference: this is an
     * ordinary edit of a field, so it is gated by `suppliers.update` and
     * needs no permission of its own. Nothing on a document already posted
     * changes — this says what the supplier is from now on.
     */
    public function updateVat(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('update', $supplier);

        $this->audit->withoutAuditing(
            fn () => $supplier->update(['is_vat_registered' => ! $supplier->is_vat_registered])
        );

        $supplier->auditCustom(
            AuditAction::Update,
            "Supplier [{$supplier->code}] is now {$supplier->vatLabel()}.",
            ['is_vat_registered' => $supplier->is_vat_registered],
        );

        return back()->with(
            'success',
            "Supplier [{$supplier->code}] is now {$supplier->vatLabel()}."
        );
    }

    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:active,inactive'],
            'vat' => ['nullable', 'in:registered,not-registered'],
        ]);

        return [
            'status' => $validated['status'] ?? null,
            'vat' => $validated['vat'] ?? null,
        ];
    }
}
