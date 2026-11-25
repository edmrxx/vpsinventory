<?php

namespace App\Http\Controllers;

use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Services\AuditService;
use App\Support\CustomerCredit;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who goods are sold to.
 *
 * One row is the walk-in customer. It is marked by a flag rather than by its
 * name, so an admin may rename it, and it cannot be deleted or switched off.
 */
class CustomerController extends Controller
{
    /** How many unpaid sales a customer's page lists. */
    protected const UNPAID_SHOWN = 50;

    public function __construct(protected AuditService $audit) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Customer::class);

        $filters = $this->validatedFilters($request);
        $today = Carbon::today();

        $query = Customer::query()
            // The delete rule asks how many sales name this customer.
            // Loading it here keeps the policy from querying once per row.
            ->withCount(['sales', 'quotations', 'salesOrders', 'pendingPayments'])
            // What each owes, and how much of it is past due: two
            // subqueries in the same query, so the flags cost nothing per row.
            ->withBalance()
            ->withOverdue($today)
            ->when($filters['status'] !== null, fn ($q) => $q->where('is_active', $filters['status'] === 'active'))
            ->when($filters['balance'] === 'owing', fn ($q) => $q->owing())
            ->when($filters['balance'] === 'overdue', fn ($q) => $q->overdue($today))
            ->when($filters['balance'] === 'over_limit', fn ($q) => $q->overLimit());

        $table = TableQuery::for($query, $request)
            ->searchable(['code', 'name', 'contact_person', 'email', 'phone'])
            // Balance is what each customer owes, the `balance_cents` that
            // withBalance() selects — never stored, so it is sorted by that
            // figure. Each one is read through the `sales_customer_balance`
            // index, as the column already was.
            ->sortable(['code', 'name', 'contact_person', 'is_active', 'created_at', 'balance' => 'balance_cents'])
            ->defaultSort('name')
            ->extra($filters);

        return Inertia::render('Customers/Index', [
            'title' => 'Customers',
            'customers' => $table->paginate()->through(fn (Customer $customer) => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'contact_person' => $customer->contact_person,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'tin' => $customer->tin,
                'address' => $customer->address,
                'notes' => $customer->notes,
                'is_active' => $customer->is_active,
                'is_walk_in' => $customer->is_walk_in,
                'created_at' => $customer->created_at?->format('d M Y'),
                'credit_limit' => $customer->credit_limit,
                'payment_terms_days' => $customer->payment_terms_days,
                'balance' => CustomerCredit::figure((int) $customer->balance_cents),
                'overdue' => CustomerCredit::figure((int) $customer->overdue_cents),
                'flags' => CustomerCredit::flags($customer, (int) $customer->balance_cents, (int) $customer->overdue_cents),
                'can' => [
                    'update' => $request->user()->can('update', $customer),
                    'delete' => $request->user()->can('delete', $customer),
                    'status' => $request->user()->can('updateStatus', $customer),
                ],
            ]),
            'filters' => $table->state(),
            'can' => [
                'create' => $request->user()->can('create', Customer::class),
                'credit' => $request->user()->can('customers.credit'),
            ],
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $this->authorize('create', Customer::class);

        $customer = Customer::create($request->validated());

        return back()->with('success', "Customer [{$customer->code}] was created.");
    }

    public function show(Request $request, Customer $customer): Response
    {
        $this->authorize('view', $customer);

        $customer->auditView();

        return Inertia::render('Customers/Show', array_merge(
            ['title' => $customer->name],
            $this->detail($request, $customer),
        ));
    }

    /**
     * The same customer, as JSON, for the dialog on the list (24 November
     * 2026), so reading one does not lose the list's place.
     *
     * Both this and `show()` call `detail()`, so the dialog cannot show a
     * different figure from the page.
     */
    public function preview(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);

        $customer->auditView();

        return response()->json($this->detail($request, $customer));
    }

    /**
     * Everything a customer shows: the record, the account, what is unpaid
     * and the last sales.
     *
     * @return array<string, mixed>
     */
    protected function detail(Request $request, Customer $customer): array
    {
        return [
            'customer' => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'contact_person' => $customer->contact_person,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'tin' => $customer->tin,
                'address' => $customer->address,
                'notes' => $customer->notes,
                'is_active' => $customer->is_active,
                'is_walk_in' => $customer->is_walk_in,
                'created_at' => $customer->created_at?->format('d M Y, g:i A'),
                'updated_at' => $customer->updated_at?->format('d M Y, g:i A'),
                'credit_limit' => $customer->credit_limit,
                'payment_terms_days' => $customer->payment_terms_days,
            ],
            // Limit, owed and what is left. Walk-in has no account.
            'account' => $customer->is_walk_in ? null : CustomerCredit::account($customer),
            // Behind on what is owed, or over the limit. Read from the
            // aging row, so this page and the aging report agree.
            'flags' => $this->flagsFor($customer),
            // What is still owed, soonest due first.
            'unpaid' => $request->user()->can('viewAny', Sale::class)
                ? $customer->sales()
                    ->where('balance_due', '>', 0)
                    ->orderBy('due_date')
                    ->orderBy('id')
                    ->limit(self::UNPAID_SHOWN)
                    ->get(['id', 'reference', 'sale_date', 'due_date', 'on_account', 'balance_due'])
                    ->map(fn (Sale $sale) => [
                        'id' => $sale->id,
                        'reference' => $sale->reference,
                        'sale_date' => $sale->sale_date?->format('d M Y'),
                        'due_date' => $sale->due_date?->format('d M Y'),
                        'overdue' => $sale->due_date !== null && $sale->due_date->lt(Carbon::today()),
                        'on_account' => $sale->on_account,
                        'balance_due' => $sale->balance_due,
                    ])
                    ->all()
                : [],
            // The last handful of sales, so the screen says something about
            // this customer rather than only repeating the form.
            'sales' => $request->user()->can('viewAny', Sale::class)
                ? $customer->sales()
                    ->latest('sale_date')
                    ->latest('id')
                    ->limit(5)
                    ->get(['id', 'reference', 'sale_date', 'total_amount', 'status'])
                    ->map(fn (Sale $sale) => [
                        'id' => $sale->id,
                        'reference' => $sale->reference,
                        'sale_date' => $sale->sale_date?->format('d M Y'),
                        'total_amount' => $sale->total_amount,
                        'status' => $sale->status->value,
                        'status_label' => $sale->status->label(),
                        'status_tone' => $sale->status->tone(),
                    ])
                    ->all()
                : [],
            'can' => [
                'update' => $request->user()->can('update', $customer),
                'delete' => $request->user()->can('delete', $customer),
                'credit' => $request->user()->can('customers.credit'),
                // Walk-in pays at the counter and never owes anything.
                'pay' => ! $customer->is_walk_in && $request->user()->can('create', CustomerPayment::class),
                'statement' => ! $customer->is_walk_in && $request->user()->can('viewAny', CustomerPayment::class),
            ],
        ];
    }

    /**
     * @return array{overdue: bool, over_limit: bool, overdue_amount: string}
     */
    protected function flagsFor(Customer $customer): array
    {
        $row = $customer->is_walk_in ? null : (CustomerCredit::aging(Carbon::today(), $customer->id)['rows'][0] ?? null);

        $overdueCents = $row === null ? 0 : (int) round(((float) $row['total'] - (float) $row['current']) * 100);

        return [
            'overdue' => $row['overdue'] ?? false,
            'over_limit' => $row['over_limit'] ?? false,
            'overdue_amount' => CustomerCredit::figure($overdueCents),
        ];
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $customer->update($request->validated());

        return back()->with('success', "Customer [{$customer->code}] was saved.");
    }

    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);

        $code = $customer->code;
        $customer->delete();

        return redirect()
            ->route('customers.index')
            ->with('success', "Customer [{$code}] was deleted.");
    }

    /**
     * Switch a customer on or off. Kept apart from the edit form so one
     * click is enough, and so the trail reads Activate or Deactivate rather
     * than Update.
     */
    public function updateStatus(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('updateStatus', $customer);

        $this->audit->withoutAuditing(
            fn () => $customer->update(['is_active' => ! $customer->is_active])
        );

        $customer->auditStatus($customer->is_active);

        return back()->with(
            'success',
            $customer->is_active
                ? "Customer [{$customer->code}] is active again."
                : "Customer [{$customer->code}] is now inactive."
        );
    }

    /**
     * The status filter above the table. Anything else in the query string
     * is refused, so the list cannot be steered from the address bar.
     *
     * @return array{status:?string,balance:?string}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:active,inactive'],
            'balance' => ['nullable', 'in:owing,overdue,over_limit'],
        ]);

        return [
            'status' => $validated['status'] ?? null,
            'balance' => $validated['balance'] ?? null,
        ];
    }
}
