<?php

namespace App\Http\Controllers;

use App\Enums\PaymentKind;
use App\Http\Requests\PaymentMethod\StorePaymentMethodRequest;
use App\Http\Requests\PaymentMethod\UpdatePaymentMethodRequest;
use App\Models\PaymentMethod;
use App\Services\AuditService;
use App\Support\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The ways a customer may pay: Cash, GCash, Maya, a bank transfer.
 *
 * A short list the owner keeps, shaped like Units: the table shows
 * everything a method carries, and the form opens in a dialog over it.
 * Adding Card later is a row here, not a change to the code.
 */
class PaymentMethodController extends Controller
{
    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PaymentMethod::class);

        $filters = $this->validatedFilters($request);

        // The count rides along on every row, so the policy can refuse a
        // delete without asking the database once per row.
        $query = PaymentMethod::query()
            ->withCount(['payments', 'customerPayments', 'supplierPayments', 'purchases'])
            ->when($filters['status'] !== null, fn ($q) => $q->where('is_active', $filters['status'] === 'active'))
            ->when($filters['kind'] !== null, fn ($q) => $q->where('kind', $filters['kind']));

        $table = TableQuery::for($query, $request)
            ->searchable(['name'])
            ->sortable(['name', 'is_active'])
            ->defaultSort('name')
            ->extra($filters);

        return Inertia::render('PaymentMethods/Index', [
            'title' => 'Payment Methods',
            'methods' => $table->paginate()->through(fn (PaymentMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
                'kind' => $method->kind->value,
                'kind_label' => $method->kind->label(),
                'is_cash' => $method->isCash(),
                'requires_reference' => $method->requires_reference,
                'payments_count' => $method->payments_count + $method->customer_payments_count + $method->supplier_payments_count + $method->purchases_count,
                'is_active' => $method->is_active,
                'can' => [
                    'update' => $request->user()->can('update', $method),
                    'delete' => $request->user()->can('delete', $method),
                    'status' => $request->user()->can('updateStatus', $method),
                ],
            ]),
            'filters' => $table->state(),
            'kinds' => PaymentKind::options(),
            'can' => ['create' => $request->user()->can('create', PaymentMethod::class)],
        ]);
    }

    public function store(StorePaymentMethodRequest $request): RedirectResponse
    {
        $this->authorize('create', PaymentMethod::class);

        $method = PaymentMethod::create($request->validated());

        return back()->with('success', "Payment method [{$method->name}] was created.");
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $this->authorize('update', $paymentMethod);

        $paymentMethod->update($request->validated());

        return back()->with('success', "Payment method [{$paymentMethod->name}] was saved.");
    }

    public function destroy(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $this->authorize('delete', $paymentMethod);

        $name = $paymentMethod->name;
        $paymentMethod->delete();

        return redirect()
            ->route('payment-methods.index')
            ->with('success', "Payment method [{$name}] was deleted.");
    }

    public function updateStatus(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $this->authorize('updateStatus', $paymentMethod);

        $this->audit->withoutAuditing(
            fn () => $paymentMethod->update(['is_active' => ! $paymentMethod->is_active])
        );

        $paymentMethod->auditStatus($paymentMethod->is_active);

        return back()->with(
            'success',
            $paymentMethod->is_active
                ? "Payment method [{$paymentMethod->name}] is active again."
                : "Payment method [{$paymentMethod->name}] is now inactive."
        );
    }

    /**
     * @return array{status:?string,kind:?string}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:active,inactive'],
            'kind' => ['nullable', 'in:'.implode(',', PaymentKind::values())],
        ]);

        return [
            'status' => $validated['status'] ?? null,
            'kind' => $validated['kind'] ?? null,
        ];
    }
}
