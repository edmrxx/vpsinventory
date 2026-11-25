<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Exceptions\InventoryException;
use App\Exceptions\PaymentException;
use App\Http\Requests\SupplierPayment\StoreSupplierPaymentRequest;
use App\Http\Requests\SupplierPayment\VoidSupplierPaymentRequest;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\SupplierPaymentPoster;
use App\Support\CustomerCredit;
use App\Support\SupplierAccount;
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
 * Money paid to suppliers against their bills (phase 24).
 *
 * Shaped like `CustomerPaymentController`: no edit, no delete, a void that
 * says why, one `detail()` the page and the dialog both read.
 */
class SupplierPaymentController extends Controller
{
    public function __construct(protected SupplierPaymentPoster $poster) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SupplierPayment::class);

        $filters = $this->validatedFilters($request);

        $query = SupplierPayment::query()
            ->with(['supplier:id,name', 'method:id,name'])
            ->withCount('allocations')
            ->when($filters['supplier_id'], fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($filters['status'], fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'], fn ($q, $from) => $q->where('payment_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->where('payment_date', '<', Carbon::parse($to)->addDay()->toDateString()));

        $table = TableQuery::for($query, $request)
            ->searchable(['reference', 'method_reference', 'supplier.name'])
            ->sortable(['reference', 'payment_date', 'amount', 'status'])
            ->defaultSort('payment_date', 'desc')
            ->extra($filters);

        return Inertia::render('SupplierPayments/Index', [
            'title' => 'Supplier Payments',
            'payments' => $table->paginate()->through(fn (SupplierPayment $payment) => [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'supplier' => $payment->supplier?->name,
                'supplier_id' => $payment->supplier_id,
                'payment_date' => $payment->payment_date?->format('d M Y'),
                'method' => $payment->method?->name,
                'method_reference' => $payment->method_reference,
                'bills_count' => $payment->allocations_count,
                'amount' => $payment->amount,
                'credit_used' => $payment->credit_used,
                'status' => $payment->status->value,
                'status_label' => $payment->status->label(),
                'status_tone' => $payment->status->tone(),
            ]),
            'filters' => $table->state(),
            'options' => [
                'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Supplier $s) => ['value' => $s->id, 'label' => $s->name])->all(),
                'statuses' => [
                    ['value' => DocumentStatus::Posted->value, 'label' => 'Posted'],
                    ['value' => DocumentStatus::Voided->value, 'label' => 'Voided'],
                ],
            ],
            'can' => ['create' => $request->user()->can('create', SupplierPayment::class)],
        ]);
    }

    /**
     * The form. `?supplier=` picks who is being paid, and the screen reloads
     * just that supplier's bills when another is picked.
     */
    public function create(Request $request): Response
    {
        $this->authorize('create', SupplierPayment::class);

        $supplierId = $request->integer('supplier') ?: null;
        $supplier = $supplierId ? Supplier::find($supplierId) : null;

        return Inertia::render('SupplierPayments/Create', [
            'title' => 'Pay a supplier',
            // Only the suppliers owed something: nobody else can be paid.
            'suppliers' => Supplier::query()
                ->whereHas('purchases', fn ($q) => $q->where('status', DocumentStatus::Posted->value)->where('balance_due', '>', 0))
                ->withSum(['purchases as owed' => fn ($q) => $q->where('status', DocumentStatus::Posted->value)->where('balance_due', '>', 0)], 'balance_due')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Supplier $s) => [
                    'value' => $s->id,
                    'label' => $s->name,
                    'owed' => number_format((float) $s->owed, 2, '.', ''),
                ])
                ->all(),
            'supplierId' => $supplier?->id,
            'account' => $supplier ? SupplierAccount::account($supplier) : null,
            'bills' => $supplier ? $this->openBills($supplier) : [],
            'paymentMethods' => PaymentMethod::options(),
            'today' => Carbon::today()->toDateString(),
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(StoreSupplierPaymentRequest $request): RedirectResponse
    {
        $this->authorize('create', SupplierPayment::class);

        if ($existing = $request->alreadySaved()) {
            if ($request->matchesSaved($existing)) {
                return redirect()
                    ->route('supplier-payments.show', $existing)
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
                    ->route('supplier-payments.show', $existing)
                    ->with('info', "Payment [{$existing->reference}] was already recorded.");
            }

            throw $e;
        } catch (PaymentException $e) {
            throw ValidationException::withMessages(['allocations' => $e->getMessage()]);
        }

        $left = SupplierAccount::owed((int) $payment->supplier_id);

        return redirect()
            ->route('supplier-payments.show', $payment)
            ->with('success', "Payment [{$payment->reference}] was recorded. "
                .'The shop now owes '.$payment->supplier->name.' '
                .settings('system.currency_symbol', '₱').number_format($left / 100, 2).'.');
    }

    public function show(Request $request, SupplierPayment $supplierPayment): Response
    {
        $this->authorize('view', $supplierPayment);

        $supplierPayment->auditView();

        return Inertia::render('SupplierPayments/Show', array_merge(
            ['title' => $supplierPayment->reference],
            $this->detail($request, $supplierPayment),
        ));
    }

    /** The same payment, as JSON, for the dialog on the list screen. */
    public function preview(Request $request, SupplierPayment $supplierPayment): JsonResponse
    {
        $this->authorize('view', $supplierPayment);

        $supplierPayment->auditView();

        return response()->json($this->detail($request, $supplierPayment));
    }

    /**
     * Everything a payment shows, on the page or in the dialog.
     *
     * @return array{payment: array<string, mixed>, can: array<string, bool>}
     */
    protected function detail(Request $request, SupplierPayment $payment): array
    {
        $payment->load([
            'supplier:id,name,code',
            'method:id,name,kind',
            'allocations' => fn ($q) => $q->orderBy('id'),
            'allocations.purchase:id,reference,supplier_reference,purchase_date,due_date,on_account,balance_due',
            'postedBy:id,name',
            'voidedBy:id,name',
        ]);

        return [
            'payment' => [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'supplier' => $payment->supplier?->name,
                'supplier_id' => $payment->supplier_id,
                'payment_date' => $payment->payment_date?->format('d M Y'),
                'method' => $payment->method?->name,
                'method_reference' => $payment->method_reference,
                'amount' => $payment->amount,
                'credit_used' => $payment->credit_used,
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
                    'purchase_id' => $allocation->purchase_id,
                    'reference' => $allocation->purchase?->reference,
                    'supplier_reference' => $allocation->purchase?->supplier_reference,
                    'purchase_date' => $allocation->purchase?->purchase_date?->format('d M Y'),
                    'due_date' => $allocation->purchase?->due_date?->format('d M Y'),
                    'amount' => $allocation->amount,
                    // What that bill owes now, after this and everything since.
                    'balance_due' => $allocation->purchase?->balance_due,
                ])->all(),
            ],
            'can' => [
                'void' => $request->user()->can('void', $payment),
                'viewPurchases' => $request->user()->can('viewAny', Purchase::class),
            ],
        ];
    }

    public function void(VoidSupplierPaymentRequest $request, SupplierPayment $supplierPayment): RedirectResponse
    {
        $this->authorize('void', $supplierPayment);

        try {
            $this->poster->void($supplierPayment, $request->validated()['reason']);
        } catch (InventoryException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Payment [{$supplierPayment->reference}] was voided. The bills it paid are owed again.");
    }

    /* ------------------------------------------------------------- Helpers */

    /**
     * What this supplier is still owed, soonest due first: the order the
     * form fills a payment in.
     *
     * @return list<array<string, mixed>>
     */
    protected function openBills(Supplier $supplier): array
    {
        $today = Carbon::today();

        return $supplier->purchases()
            ->where('status', DocumentStatus::Posted->value)
            ->where('balance_due', '>', 0)
            ->orderByRaw('COALESCE(due_date, purchase_date)')
            ->orderBy('id')
            ->limit(StoreSupplierPaymentRequest::MAX_BILLS)
            ->get(['id', 'reference', 'supplier_reference', 'purchase_date', 'due_date', 'total_amount', 'on_account', 'balance_due'])
            ->map(fn (Purchase $bill) => [
                'id' => $bill->id,
                'reference' => $bill->reference,
                'supplier_reference' => $bill->supplier_reference,
                'purchase_date' => $bill->purchase_date?->format('d M Y'),
                'purchase_date_iso' => $bill->purchase_date?->toDateString(),
                'due_date' => $bill->due_date?->format('d M Y'),
                'overdue' => $bill->due_date !== null && $bill->due_date->lt($today),
                'on_account' => $bill->on_account,
                'balance_due' => $bill->balance_due,
            ])
            ->all();
    }

    /**
     * @return array{supplier_id:?int, status:?string, from:?string, to:?string}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'status' => ['nullable', 'in:'.implode(',', DocumentStatus::values())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [
            'supplier_id' => isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null,
            'status' => $validated['status'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
    }
}
