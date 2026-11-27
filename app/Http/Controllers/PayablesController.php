<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\SupplierAccount;
use App\Support\TableQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the shop owes its suppliers (phase 24): every bill still owed, one
 * row each, filtered by supplier, status and the month it falls due, with
 * the totals and the aging over the filtered rows, not just the page.
 *
 * The client's request of 12 November, "show what we owe our suppliers".
 * Behind `supplier_payments.view`, like the payments themselves.
 */
class PayablesController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SupplierPayment::class);

        $validated = $request->validate([
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'status' => ['nullable', 'in:overdue,current'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $filters = [
            'supplier_id' => isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null,
            'status' => $validated['status'] ?? null,
            'month' => $validated['month'] ?? null,
        ];

        $today = Carbon::today();

        $table = TableQuery::for(
            SupplierAccount::openBills($today, $filters)->with('supplier:id,name'),
            $request,
        )
            ->searchable(['reference', 'supplier_reference', 'supplier.name'])
            ->sortable(['reference', 'purchase_date', 'due_date', 'balance_due'])
            ->defaultSort('due_date', 'asc')
            ->extra($filters);

        $user = $request->user();

        return Inertia::render('Payables/Index', [
            'title' => 'Payables',
            'bills' => $table->paginate()->through(fn (Purchase $bill) => [
                'id' => $bill->id,
                'reference' => $bill->reference,
                'supplier_reference' => $bill->supplier_reference,
                'supplier' => $bill->supplier?->name,
                'supplier_id' => $bill->supplier_id,
                'purchase_date' => $bill->purchase_date?->format('d M Y'),
                'due_date' => $bill->due_date?->format('d M Y'),
                'days_overdue' => $bill->due_date && $bill->due_date->lt($today) ? (int) $bill->due_date->diffInDays($today) : 0,
                'on_account' => $bill->on_account,
                'balance_due' => $bill->balance_due,
            ]),
            'filters' => $table->state(),
            'totals' => SupplierAccount::openTotals($today, $filters),
            'options' => [
                'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Supplier $s) => ['value' => $s->id, 'label' => $s->name])->all(),
            ],
            'today' => $today->format('d M Y'),
            'can' => [
                'pay' => $user->can('create', SupplierPayment::class),
                'viewPurchases' => $user->can('viewAny', Purchase::class),
            ],
        ]);
    }

    /**
     * The Paid side (phase 24.5, CR-5): what the shop paid each supplier in a
     * period — on delivery and later — beside what it still owes, and how it
     * paid. The client's request after the canvass meeting: "which suppliers
     * were paid in cash, and which are payables". Every figure links to the
     * list behind it. This month by default.
     */
    public function paid(Request $request): Response
    {
        $this->authorize('viewAny', SupplierPayment::class);

        $validated = $request->validate([
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $today = Carbon::today();
        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : $today->copy()->startOfMonth();
        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : $today->copy();
        $supplierId = isset($validated['supplier_id']) ? (int) $validated['supplier_id'] : null;

        $user = $request->user();

        return Inertia::render('Payables/Paid', [
            'title' => 'Payables · Paid',
            'today' => $today->format('d M Y'),
            'filters' => [
                'supplier_id' => $supplierId,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'period' => $from->format('d M Y').' to '.$to->format('d M Y'),
            'summary' => SupplierAccount::paidSummary($from, $to, $today, ['supplier_id' => $supplierId]),
            'options' => [
                'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name'])
                    ->map(fn (Supplier $s) => ['value' => $s->id, 'label' => $s->name])->all(),
            ],
            'can' => [
                'pay' => $user->can('create', SupplierPayment::class),
                'viewPurchases' => $user->can('viewAny', Purchase::class),
            ],
        ]);
    }
}
