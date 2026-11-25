<?php

namespace App\Http\Controllers;

use App\Enums\PendingPaymentStatus;
use App\Exceptions\PendingPaymentException;
use App\Http\Requests\PendingPayment\CancelPendingPaymentRequest;
use App\Http\Requests\PendingPayment\StorePendingPaymentRequest;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PendingPayment;
use App\Services\PendingPaymentDesk;
use App\Support\CustomerCredit;
use App\Support\StaffOptions;
use App\Support\TableQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pending payment: the kiosk takes the order, the cashier takes the money
 * (CR-2, the client's meeting of 24 November 2026).
 *
 * The kiosk sends the counter here instead of taking the money; the order
 * holds its goods at the till's store until it is paid on the till — as a
 * sale through `POST /sales`, at exactly these lines and prices — or
 * cancelled. This controller lists, shows, sends and cancels; it never
 * posts a sale and never moves stock.
 */
class PendingPaymentController extends Controller
{
    public function __construct(protected PendingPaymentDesk $desk) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PendingPayment::class);

        $filters = $this->validatedFilters($request);
        $today = Carbon::today();

        $query = PendingPayment::query()
            ->with(['customer:id,name', 'location:id,name', 'takenBy:id,name', 'sale:id,reference'])
            ->withCount('lines')
            ->when($filters['status'] === 'waiting', fn ($q) => $q->waiting($today))
            ->when($filters['status'] === 'expired', fn ($q) => $q->where('status', PendingPaymentStatus::Pending->value)
                ->where('hold_until', '<', $today->toDateString()))
            ->when(in_array($filters['status'], [PendingPaymentStatus::Paid->value, PendingPaymentStatus::Cancelled->value], true),
                fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['location_id'], fn ($q, $id) => $q->where('location_id', $id))
            ->when($filters['taken_by'], fn ($q, $id) => $q->where('taken_by', $id))
            ->when($filters['from'], fn ($q, $from) => $q->where('order_date', '>=', $from))
            ->when($filters['to'], fn ($q, $to) => $q->where('order_date', '<', Carbon::parse($to)->addDay()->toDateString()));

        $table = TableQuery::for($query, $request)
            ->searchable(['reference', 'note', 'customer.name'])
            ->sortable(['reference', 'order_date', 'total_amount', 'status'])
            ->defaultSort('reference', 'desc')
            ->extra($filters);

        $user = $request->user();

        return Inertia::render('PendingPayments/Index', [
            'title' => 'Pending Payments',
            'payments' => $table->paginate()->through(function (PendingPayment $pending) use ($today, $user) {
                $standing = $pending->standing($today);

                return [
                    'id' => $pending->id,
                    'reference' => $pending->reference,
                    'order_date' => $pending->order_date?->format('d M Y'),
                    'sent_at' => $pending->created_at?->format('g:i A'),
                    'customer' => $pending->customer?->name,
                    'location' => $pending->location?->name,
                    'location_id' => $pending->location_id,
                    'taken_by' => $pending->takenBy?->name,
                    'note' => $pending->note,
                    'sale' => $pending->sale?->reference,
                    'sale_id' => $pending->sale_id,
                    'lines_count' => $pending->lines_count,
                    'total_amount' => $pending->total_amount,
                    'status' => $standing['value'],
                    'status_label' => $standing['label'],
                    'status_tone' => $standing['tone'],
                    'can' => [
                        'pay' => $user->can('pay', $pending) && $user->can('kiosk.use'),
                        'receive' => $user->can('pay', $pending),
                        'cancel' => $user->can('cancel', $pending),
                    ],
                ];
            }),
            'filters' => $table->state(),
            'options' => [
                'statuses' => [
                    ['value' => 'waiting', 'label' => 'Awaiting payment'],
                    ['value' => PendingPaymentStatus::Paid->value, 'label' => 'Paid (became a sale)'],
                    ['value' => PendingPaymentStatus::Cancelled->value, 'label' => 'Cancelled'],
                    ['value' => 'expired', 'label' => 'Expired unpaid'],
                ],
                'locations' => Location::options(),
                'staff' => StaffOptions::all(),
            ],
            'waiting' => PendingPayment::query()->waiting($today)->count(),
        ]);
    }

    /**
     * Send the counter to the cashier. From the kiosk; back to the kiosk,
     * with the order's slip one tap away.
     */
    public function store(StorePendingPaymentRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // A second tap on a slow tablet: the same order, once.
        if (! empty($data['idempotency_key'])
            && ($existing = PendingPayment::firstWhere('idempotency_key', $data['idempotency_key']))) {
            return back()->with('info', "Order [{$existing->reference}] was already sent to the cashier.");
        }

        try {
            $pending = $this->desk->send($data, $data['lines'], $request->user());
        } catch (PendingPaymentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return back()
            ->with('success', "Order [{$pending->reference}] was sent to the cashier. Its goods are held until it is paid.")
            ->with('pending', $this->detail($request, $pending)['pending']);
    }

    public function show(Request $request, PendingPayment $pendingPayment): Response
    {
        $this->authorize('view', $pendingPayment);

        $pendingPayment->auditView();

        return Inertia::render('PendingPayments/Show', array_merge(
            ['title' => $pendingPayment->reference],
            $this->detail($request, $pendingPayment),
        ));
    }

    /** The same order, as JSON, for the dialog on the list. */
    public function preview(Request $request, PendingPayment $pendingPayment): JsonResponse
    {
        $this->authorize('view', $pendingPayment);

        $pendingPayment->auditView();

        return response()->json($this->detail($request, $pendingPayment));
    }

    public function cancel(CancelPendingPaymentRequest $request, PendingPayment $pendingPayment): RedirectResponse
    {
        $this->authorize('cancel', $pendingPayment);

        try {
            $this->desk->cancel($pendingPayment, $request->user(), $request->validated()['reason']);
        } catch (PendingPaymentException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Order [{$pendingPayment->reference}] was cancelled; its goods are free again.");
    }

    /**
     * Everything an order shows: on its page, in the dialog, on the till's
     * Pending tab and on its slip. The lines carry what the till posts —
     * product, unit, quantity as typed and price — so the sale is exactly
     * what was sent.
     *
     * The till lists many and has its own payment methods, so it leaves out
     * `receive` (`$withPaymentBox` false).
     *
     * @return array{pending: array<string, mixed>, can: array<string, bool>, receive?: array<string, mixed>|null}
     */
    public function detail(Request $request, PendingPayment $pending, bool $withPaymentBox = true): array
    {
        $pending->loadMissing([
            'customer:id,name,code,is_walk_in,credit_limit,payment_terms_days',
            'location:id,name',
            'lines' => fn ($q) => $q->orderBy('id'),
            'lines.product:id,sku,name,unit_id',
            'lines.product.unit:id,code',
            'lines.unit:id,code',
            'takenBy:id,name',
            'cancelledBy:id,name',
            'sale:id,reference',
        ]);

        $user = $request->user();
        $standing = $pending->standing();
        $receives = $user->can('pay', $pending);

        return [
            'pending' => self::shape($pending, $standing),
            'can' => [
                // Open the till on it, for somebody at the kiosk.
                'pay' => $receives && $user->can('kiosk.use'),
                // Take the money right here, in the back office (CR-2, the
                // user's GO! of 24 November 2026): only Record a sale.
                'receive' => $receives,
                'cancel' => $user->can('cancel', $pending),
            ],
            // What the payment box needs, only for somebody who may use it.
            'receive' => $receives && $withPaymentBox ? [
                'methods' => PaymentMethod::options(),
                'today' => Carbon::today()->toDateString(),
                'extend_terms' => $user->can('customers.credit'),
            ] : null,
        ];
    }

    /**
     * One order as the screens read it.
     *
     * @param  array{value:string, label:string, tone:string}  $standing
     * @return array<string, mixed>
     */
    public static function shape(PendingPayment $pending, array $standing): array
    {
        return [
            'id' => $pending->id,
            'reference' => $pending->reference,
            'order_date' => $pending->order_date?->format('d M Y'),
            'hold_until' => $pending->hold_until?->format('d M Y'),
            'sent_at' => $pending->created_at?->format('d M Y, g:i A'),
            'status' => $standing['value'],
            'status_label' => $standing['label'],
            'status_tone' => $standing['tone'],
            'customer' => $pending->customer?->name,
            'customer_id' => $pending->customer_id,
            // The customer's credit as of now, so the cashier may put it on
            // account; null when they have none.
            'credit' => $pending->customer && $pending->customer->takesCredit()
                ? CustomerCredit::account($pending->customer)
                : null,
            'location' => $pending->location?->name,
            'location_id' => $pending->location_id,
            'taken_by' => $pending->takenBy?->name,
            'note' => $pending->note,
            'subtotal' => $pending->subtotal,
            'discount' => $pending->discount,
            'total_amount' => $pending->total_amount,
            'sale' => $pending->sale ? ['id' => $pending->sale->id, 'reference' => $pending->sale->reference] : null,
            'paid_at' => $pending->paid_at?->format('d M Y, g:i A'),
            'cancelled_at' => $pending->cancelled_at?->format('d M Y, g:i A'),
            'cancelled_by' => $pending->cancelledBy?->name,
            'cancel_reason' => $pending->cancel_reason,
            'lines' => $pending->lines->map(fn ($line) => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'unit_id' => $line->unit_id,
                'sku' => $line->product?->sku,
                'name' => $line->product?->name,
                // As typed: 2 box, not the 24 pcs it is on the shelf.
                'quantity' => $line->unit_quantity,
                'unit_code' => $line->unit?->code ?? $line->product?->unit?->code,
                'unit_price' => $line->unit_price,
                'line_total' => $line->line_total,
            ])->all(),
        ];
    }

    /**
     * @return array{status:?string, location_id:?int, taken_by:?int, from:?string, to:?string}
     */
    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:waiting,paid,cancelled,expired'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'taken_by' => ['nullable', 'integer', 'exists:users,id'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [
            'status' => $validated['status'] ?? null,
            'location_id' => isset($validated['location_id']) ? (int) $validated['location_id'] : null,
            'taken_by' => isset($validated['taken_by']) ? (int) $validated['taken_by'] : null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
    }
}
