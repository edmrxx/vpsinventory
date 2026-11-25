<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Exceptions\InventoryException;
use App\Exceptions\PaymentException;
use App\Http\Requests\CustomerPayment\StoreCustomerPaymentRequest;
use App\Http\Requests\CustomerPayment\VoidCustomerPaymentRequest;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Services\CustomerPaymentPoster;
use App\Support\CustomerCredit;
use App\Support\StaffOptions;
use App\Support\TableQuery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Money a customer brings in against what they owe (phase 19).
 *
 * Shaped like `SaleController`: no edit, no delete, a void that says why.
 * The page and the dialog both read `detail()`, so they cannot disagree.
 *
 * Never called a receipt, on any screen: an official or collection receipt
 * is a BIR document this system does not issue.
 */
class CustomerPaymentController extends Controller
{
    public function __construct(protected CustomerPaymentPoster $poster) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CustomerPayment::class);

        $filters = $this->validatedFilters($request);

        $query = CustomerPayment::query()
            ->with(['customer:id,name', 'method:id,name'])
            ->withCount('allocations')
            ->when($filters['customer_id'], fn ($q, $id) => $q->where('customer_id', $id))
            ->when($filters['payment_method_id'], fn ($q, $id) => $q->where('payment_method_id', $id))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'], fn ($q, $from) => $q->where('payment_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->where('payment_date', '<', Carbon::parse($to)->addDay()->toDateString()))
            ->when($filters['posted_by'], fn ($q, $id) => $q->where('posted_by', $id));

        $table = TableQuery::for($query, $request)
            ->searchable(['reference', 'method_reference', 'customer.name'])
            ->sortable(['reference', 'payment_date', 'amount', 'status', 'created_at'])
            ->defaultSort('payment_date', 'desc')
            ->extra($filters);

        return Inertia::render('CustomerPayments/Index', [
            'title' => 'Customer Payments',
            'payments' => $table->paginate()->through(fn (CustomerPayment $payment) => [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'customer' => $payment->customer?->name,
                'customer_id' => $payment->customer_id,
                'payment_date' => $payment->payment_date?->format('d M Y'),
                'method' => $payment->method?->name,
                'method_reference' => $payment->method_reference,
                'invoices_count' => $payment->allocations_count,
                'amount' => $payment->amount,
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'status_tone' => $payment->status->tone(),
            ]),
            'filters' => $table->state(),
            'options' => [
                'customers' => Customer::options(),
                'methods' => PaymentMethod::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (PaymentMethod $m) => ['value' => $m->id, 'label' => $m->name])->all(),
                'staff' => StaffOptions::all(),
                'statuses' => [
                    ['value' => DocumentStatus::Posted->value, 'label' => 'Posted'],
                    ['value' => DocumentStatus::Voided->value, 'label' => 'Voided'],
                ],
            ],
            'can' => [
                'create' => $request->user()->can('create', CustomerPayment::class),
            ],
        ]);
    }

    /**
     * The form. `?customer=` picks who is paying, and the screen reloads
     * just the invoices when a different customer is picked.
     */
    public function create(Request $request): Response
    {
        $this->authorize('create', CustomerPayment::class);

        $customerId = $request->integer('customer') ?: null;
        $customer = $customerId ? Customer::where('is_walk_in', false)->find($customerId) : null;

        return Inertia::render('CustomerPayments/Create', [
            'title' => 'Record a customer payment',
            // Only the customers who owe something: nobody else can pay.
            'customers' => Customer::query()
                ->where('is_walk_in', false)
                ->owing()
                ->withBalance()
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn (Customer $c) => [
                    'value' => $c->id,
                    'label' => $c->name,
                    'balance' => CustomerCredit::figure((int) $c->balance_cents),
                ])
                ->all(),
            'customerId' => $customer?->id,
            'account' => $customer ? CustomerCredit::account($customer) : null,
            'invoices' => $customer ? $this->openInvoices($customer) : [],
            'paymentMethods' => PaymentMethod::options(),
            'today' => Carbon::today()->toDateString(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreCustomerPaymentRequest $request): RedirectResponse
    {
        $this->authorize('create', CustomerPayment::class);

        if ($existing = $request->alreadySaved()) {
            if ($request->matchesSaved($existing)) {
                return redirect()
                    ->route('customer-payments.show', $existing)
                    ->with('info', "Payment [{$existing->reference}] was already recorded.");
            }

            throw ValidationException::withMessages([
                'idempotency_key' => "This form already recorded payment [{$existing->reference}]. "
                    .'Save again to record what is on screen now as a new payment.',
            ]);
        }

        $data = $request->validated();

        try {
            $payment = $this->poster->post($data, $data['allocations']);
        } catch (UniqueConstraintViolationException $e) {
            if ($existing = $request->alreadySaved()) {
                return redirect()
                    ->route('customer-payments.show', $existing)
                    ->with('info', "Payment [{$existing->reference}] was already recorded.");
            }

            throw $e;
        } catch (PaymentException $e) {
            // The form weighed it already; something changed in between,
            // such as a return taken off one of the invoices.
            throw ValidationException::withMessages(['allocations' => $e->getMessage()]);
        }

        $left = CustomerCredit::owed((int) $payment->customer_id);

        return redirect()
            ->route('customer-payments.show', $payment)
            ->with('success', "Payment [{$payment->reference}] was recorded. "
                .$payment->customer->name.' now owes '
                .settings('system.currency_symbol', '₱').number_format($left / 100, 2).'.');
    }

    public function show(Request $request, CustomerPayment $customerPayment): Response
    {
        $this->authorize('view', $customerPayment);

        $customerPayment->auditView();

        return Inertia::render('CustomerPayments/Show', array_merge(
            ['title' => $customerPayment->reference],
            $this->detail($request, $customerPayment),
        ));
    }

    /** The same payment, as JSON, for the dialog on the list screen. */
    public function preview(Request $request, CustomerPayment $customerPayment): JsonResponse
    {
        $this->authorize('view', $customerPayment);

        $customerPayment->auditView();

        return response()->json($this->detail($request, $customerPayment));
    }

    /**
     * Everything a payment shows, on the page or in the dialog.
     *
     * @return array{payment: array<string, mixed>, can: array<string, bool>}
     */
    protected function detail(Request $request, CustomerPayment $payment): array
    {
        $payment->load([
            'customer:id,name,code',
            'method:id,name,kind',
            'allocations' => fn ($q) => $q->orderBy('id'),
            'allocations.sale:id,reference,sale_date,due_date,on_account,balance_due',
            'postedBy:id,name',
            'voidedBy:id,name',
        ]);

        return [
            'payment' => [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'customer' => $payment->customer?->name,
                'customer_id' => $payment->customer_id,
                'customer_code' => $payment->customer?->code,
                'payment_date' => $payment->payment_date?->format('d M Y'),
                'method' => $payment->method?->name,
                'method_reference' => $payment->method_reference,
                'amount' => $payment->amount,
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'status_tone' => $payment->status->tone(),
                'notes' => $payment->notes,
                'posted_at' => $payment->posted_at?->format('d M Y, g:i A'),
                'posted_by' => $payment->postedBy?->name,
                'voided_at' => $payment->voided_at?->format('d M Y, g:i A'),
                'voided_by' => $payment->voidedBy?->name,
                'void_reason' => $payment->void_reason,
                'allocations' => $payment->allocations->map(fn ($allocation) => [
                    'id' => $allocation->id,
                    'sale_id' => $allocation->sale_id,
                    'reference' => $allocation->sale?->reference,
                    'sale_date' => $allocation->sale?->sale_date?->format('d M Y'),
                    'due_date' => $allocation->sale?->due_date?->format('d M Y'),
                    'on_account' => $allocation->sale?->on_account,
                    'amount' => $allocation->amount,
                    // What that invoice owes now, after this and everything since.
                    'balance_due' => $allocation->sale?->balance_due,
                ])->all(),
            ],
            'can' => [
                'void' => $request->user()->can('void', $payment),
                'viewSales' => $request->user()->can('viewAny', Sale::class),
            ],
        ];
    }

    public function void(VoidCustomerPaymentRequest $request, CustomerPayment $customerPayment): RedirectResponse
    {
        $this->authorize('void', $customerPayment);

        try {
            $this->poster->void($customerPayment, $request->validated()['reason']);
        } catch (InventoryException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Payment [{$customerPayment->reference}] was voided. The invoices it paid are owed again.");
    }

    /* ------------------------------------------------------------- Helpers */

    /**
     * What this customer still owes, soonest due first: the order the form
     * fills a payment in.
     *
     * @return list<array<string, mixed>>
     */
    protected function openInvoices(Customer $customer): array
    {
        $today = Carbon::today();

        return $customer->sales()
            ->where('status', DocumentStatus::Posted->value)
            ->where('balance_due', '>', 0)
            ->orderByRaw('COALESCE(due_date, sale_date)')
            ->orderBy('id')
            ->limit(StoreCustomerPaymentRequest::MAX_INVOICES)
            ->get(['id', 'reference', 'sale_date', 'due_date', 'total_amount', 'on_account', 'balance_due'])
            ->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'sale_date' => $sale->sale_date?->format('d M Y'),
                'sale_date_iso' => $sale->sale_date?->toDateString(),
                'due_date' => $sale->due_date?->format('d M Y'),
                'overdue' => $sale->due_date !== null && $sale->due_date->lt($today),
                'on_account' => $sale->on_account,
                'balance_due' => $sale->balance_due,
            ])
            ->all();
    }

    /**
     * @return array{customer_id:?int, payment_method_id:?int, status:?string, from:?string, to:?string, posted_by:?int}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'status' => ['nullable', 'in:'.implode(',', DocumentStatus::values())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'posted_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        return [
            'customer_id' => isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
            'payment_method_id' => isset($validated['payment_method_id']) ? (int) $validated['payment_method_id'] : null,
            'status' => $validated['status'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'posted_by' => isset($validated['posted_by']) ? (int) $validated['posted_by'] : null,
        ];
    }
}
