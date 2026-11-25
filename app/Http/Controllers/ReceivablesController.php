<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Support\CustomerCredit;
use App\Support\StaffOptions;
use App\Support\Quantity;
use App\Support\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What customers owe, read three ways: one customer's statement of account
 * and the aging of every balance (phase 19), and the Collectibles list of
 * every invoice still owed, by month and customer (phase 19.1).
 *
 * Read-only, with no write routes, like the reports. Every figure comes from
 * `CustomerCredit`, the only place a balance is read, so the statement, the
 * aging report, the customer list and the dashboard cannot disagree.
 *
 * Both sit behind `customer_payments.view`: they show the same money the
 * payments list does.
 */
class ReceivablesController extends Controller
{
    public function statement(Request $request, Customer $customer): Response
    {
        $this->authorize('viewAny', CustomerPayment::class);
        abort_if($customer->is_walk_in, 404);

        [$from, $to] = $this->validatedRange($request);

        $customer->auditView("Viewed the statement of account of customer [{$customer->code}]");

        // As of today, whatever the period: what the customer owes now,
        // split by how far past due. One row, or none when nothing is owed.
        $aging = CustomerCredit::aging(Carbon::today(), $customer->id);
        $row = $aging['rows'][0] ?? null;

        return Inertia::render('Customers/Statement', [
            'title' => 'Statement · '.$customer->name,
            'customer' => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'contact_person' => $customer->contact_person,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'tin' => $customer->tin,
                'address' => $customer->address,
            ],
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $from->format('d M Y').' to '.$to->format('d M Y'),
                'from_label' => $from->format('d M Y'),
            ],
            'statement' => CustomerCredit::statement($customer, $from, $to),
            'account' => CustomerCredit::account($customer),
            'aging' => $aging,
            'flags' => ['overdue' => $row['overdue'] ?? false, 'over_limit' => $row['over_limit'] ?? false],
            'printedOn' => Carbon::now()->format('d M Y, g:i A'),
            'can' => [
                'pay' => $request->user()->can('create', CustomerPayment::class),
            ],
        ]);
    }

    public function aging(Request $request): Response
    {
        $this->authorize('viewAny', CustomerPayment::class);

        $today = Carbon::today();

        return Inertia::render('Aging/Index', [
            'title' => 'Aging',
            'asOf' => $today->format('d M Y'),
            'aging' => CustomerCredit::aging($today),
            'can' => [
                'pay' => $request->user()->can('create', CustomerPayment::class),
            ],
        ]);
    }

    /**
     * Every invoice still owed, one row each, filtered by month, customer,
     * status and staff. What is owed is `balance_due`, as everywhere else;
     * the totals above the table add up the same filtered rows.
     */
    public function collectibles(Request $request): Response
    {
        $this->authorize('viewAny', CustomerPayment::class);

        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'month' => ['nullable', 'date_format:Y-m'],
            'by' => ['nullable', 'in:due,sold'],
            'status' => ['nullable', 'in:overdue,current'],
            'posted_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $filters = [
            'customer_id' => isset($validated['customer_id']) ? (int) $validated['customer_id'] : null,
            'month' => $validated['month'] ?? null,
            'by' => $validated['by'] ?? 'due',
            'status' => $validated['status'] ?? null,
            'posted_by' => isset($validated['posted_by']) ? (int) $validated['posted_by'] : null,
        ];

        $today = Carbon::today();

        $table = TableQuery::for(
            CustomerCredit::openInvoices($today, $filters)->with('customer:id,name,code'),
            $request,
        )
            ->searchable(['reference', 'customer.name'])
            ->sortable(['reference', 'sale_date', 'due_date', 'balance_due'])
            ->defaultSort('due_date', 'asc')
            ->extra($filters);

        return Inertia::render('Collectibles/Index', [
            'title' => 'Collectibles',
            'asOf' => $today->format('d M Y'),
            'invoices' => $table->paginate()->through(function (Sale $sale) use ($today) {
                $due = ($sale->due_date ?? $sale->sale_date)?->copy()->startOfDay();
                $late = $due !== null && $due->lt($today) ? (int) $due->diffInDays($today) : 0;

                return [
                    'id' => $sale->id,
                    'reference' => $sale->reference,
                    'customer_id' => $sale->customer_id,
                    'customer' => $sale->customer?->name,
                    'sale_date' => $sale->sale_date?->format('d M Y'),
                    'due_date' => $due?->format('d M Y'),
                    'days_overdue' => $late,
                    'total_amount' => $sale->total_amount,
                    'on_account' => $sale->on_account,
                    // What returns and payments have taken off it so far.
                    'settled' => CustomerCredit::figure(
                        Quantity::centavos((float) $sale->on_account) - Quantity::centavos((float) $sale->balance_due)
                    ),
                    'balance_due' => $sale->balance_due,
                ];
            }),
            'totals' => CustomerCredit::openTotals($today, $filters),
            'filters' => $table->state(),
            'options' => [
                'customers' => Customer::query()
                    ->where('is_walk_in', false)
                    ->owing()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Customer $c) => ['value' => $c->id, 'label' => $c->name])
                    ->all(),
                'staff' => StaffOptions::all(),
            ],
            'can' => [
                'pay' => $request->user()->can('create', CustomerPayment::class),
                'viewSales' => $request->user()->can('viewAny', Sale::class),
            ],
        ]);
    }

    /**
     * The statement's period. Defaults to the start of the month two months
     * back, to today: a quarter, the usual length of a statement.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    protected function validatedRange(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.Carbon::today()->toDateString()],
        ]);

        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : Carbon::today();
        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])
            : $to->copy()->subMonthsNoOverflow(2)->startOfMonth();

        return [$from->startOfDay(), $to->startOfDay()];
    }
}
