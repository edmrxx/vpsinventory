<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Enums\LocationType;
use App\Models\Category;
use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PendingPayment;
use App\Models\Product;
use App\Models\Sale;
use App\Services\InventoryMetrics;
use App\Support\Quantity;
use App\Support\Reservations;
use App\Support\UnitConversion;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The till on the shared tablet.
 *
 * A different screen for the same job, not a different way of doing it.
 * It posts to `POST /sales`, which means the same Form Request, the same
 * `SalePoster`, the same FIFO batches and the same audit trail as the
 * ordinary sales form. A second way to sell would be a second place for
 * the stock arithmetic to go wrong.
 *
 * It is behind `kiosk.use`, the same permission that decides who may sign
 * in at the tablet. Somebody who may not sign in there has no business on
 * the screen it opens.
 */
class PosController extends Controller
{
    /** How many rows of the day's sales are sent to the screen. */
    protected const ROWS = 50;

    public function __construct(protected InventoryMetrics $metrics) {}

    public function index(Request $request): Response
    {
        $date = $this->chosenDay($request);

        // Only what can actually be sold. A product with nothing on the
        // shelf is still shown, greyed out, because "we are out of that"
        // is a useful answer at the counter.
        // What is reserved for customer orders cannot be sold at the counter
        // (phase 29), so the till shows what is free, not what is there.
        //
        // And only at the place it sells from (phase 31.4): a store, never a
        // warehouse — the one asked for, else the default, else the first
        // store. A shop with no store on the list sells from the default.
        $location = Location::startingAt($request->integer('location') ?: null, null, LocationType::Store)
            ?? Location::startingAt(null);
        $held = Reservations::reserved(locationId: $location?->id);
        $there = $location ? Reservations::atLocation(null, $location->id) : [];

        $products = Product::query()
            ->where('is_active', true)
            ->with(['category:id,name', ...UnitConversion::WITH])
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'photo_path', 'selling_price', 'on_hand', 'category_id', 'unit_id', ...Product::SEARCH_COLUMNS])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                // Searched, and a scan of the barcode adds it (phase 30).
                'barcode' => $product->barcode,
                'brand' => $product->brand,
                'model' => $product->model,
                'name' => $product->name,
                'photo_url' => $product->photoUrl(),
                'price' => $product->selling_price,
                'on_hand' => Reservations::available(Quantity::fromMilli($there[$product->id] ?? 0), $held[$product->id] ?? 0),
                'unit' => $product->unit?->code,
                // A tap adds one; the box takes 2.5 only when this is on.
                'decimals' => (bool) $product->unit?->allows_decimals,
                // Its own unit first, then any others it is sold in: a tap
                // adds one of its own unit, and the line can switch to a box.
                'units' => UnitConversion::options($product),
                'category_id' => $product->category_id,
                'category' => $product->category?->name,
            ])
            ->all();

        return Inertia::render('Pos/Index', [
            'title' => 'Till',
            'products' => $products,

            // Where the till sells from, and the stores it may switch to.
            'locationId' => $location?->id,
            'locations' => $this->tillLocations($location),

            // Only the categories that have something to sell, so the tabs
            // do not offer an empty shelf.
            'categories' => Category::query()
                ->where('is_active', true)
                ->whereHas('products', fn ($q) => $q->where('is_active', true))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Category $c) => ['value' => $c->id, 'label' => $c->name])
                ->all(),

            // The counter serves whoever walks up, so a sale starts on
            // Walk-in and goes back to it after every sale. A regular can
            // be picked from the list instead, and one with credit can have
            // the sale put on account.
            'customer' => $this->walkIn(),
            'customers' => Customer::options(activeOnly: true, withCredit: true),

            // Sales parked to finish later, newest first. Every hold on the
            // till, not just this cashier's: the tablet is shared, and the
            // customer comes back to the counter, not to a person.
            'holds' => $this->holds(),

            // Orders the kiosk sent to the cashier (CR-2), waiting to be
            // paid at this store, oldest first: the next customer in the
            // queue is the one who has waited longest. `?pending=` opens one
            // to take its money, from the Pending payments screen.
            'pending' => $this->pendingAt($request, $location),
            'openPending' => $request->integer('pending') ?: null,
            'pendingKey' => (string) Str::uuid(),

            'today' => now()->toDateString(),

            // How the customer may pay: cash first, then the rest by name.
            'paymentMethods' => PaymentMethod::options(),

            // Carried into the sale, so a tap that sends twice over a slow
            // connection records one sale rather than two.
            'idempotencyKey' => (string) Str::uuid(),

            'cashier' => [
                'name' => $request->user()->name,
                'username' => $request->user()->username,
            ],

            // What has gone through the counter today. Today only: the
            // person at the till asks "how much have we taken?" and
            // "where is the one I just rang up?", not "what happened in
            // March". The Sales screen answers that.
            'sales' => $this->salesOn($date),

            // Which days of that month have anything on them, so the
            // calendar can mark them. One grouped query, and it answers
            // the question somebody always asks next: "when were we
            // actually open?"
            'tradedOn' => $this->tradedOn($date),

            'day' => [
                'date' => $date->toDateString(),
                'label' => $date->isToday()
                    ? 'Today'
                    : ($date->isYesterday() ? 'Yesterday' : $date->format('D, j M Y')),
                'is_today' => $date->isToday(),
                'max' => Carbon::today()->toDateString(),
            ],

            'can' => [
                // Voiding from the counter is allowed if the account
                // holds it. Whether it should is a decision for whoever
                // ticks the boxes on the Roles screen.
                'void' => $request->user()->can('sales.void'),
                // May give a sale on account more days than the customer's
                // usual terms (phase 18.1).
                'extendTerms' => $request->user()->can('customers.credit'),
                // Take the money here, and park a sale: a sale needs
                // `sales.create`. Admin may untick it for a kiosk-only role
                // that only sends orders to the cashier (CR-2).
                'takePayment' => $request->user()->can('create', Sale::class),
                // Pending payment comes with the kiosk itself (CR-2): anyone
                // here may send one, and whoever may take payment receives
                // them on the Pending tab.
                'sendPending' => $request->user()->can('create', PendingPayment::class),
                'seePending' => $request->user()->can('create', Sale::class),
            ],
        ]);
    }

    /**
     * The pending payments waiting at this till's store (CR-2), oldest
     * first, each shaped as its own page reads it. Whoever may take payment
     * sees them all; a kiosk that only sends sees its own, so it can cancel.
     *
     * @return list<array<string, mixed>>
     */
    protected function pendingAt(Request $request, ?Location $location): array
    {
        if (! $location) {
            return [];
        }

        $user = $request->user();
        $seesAll = $user->can('create', Sale::class);

        $controller = app(PendingPaymentController::class);

        return PendingPayment::query()
            ->waiting()
            ->where('location_id', $location->id)
            ->when(! $seesAll, fn ($q) => $q->where('taken_by', $user->id))
            // What `detail()` reads, loaded once for the lot.
            ->with([
                'customer:id,name,code,is_walk_in,credit_limit,payment_terms_days',
                'location:id,name',
                'lines' => fn ($q) => $q->orderBy('id'),
                'lines.product:id,sku,name,unit_id',
                'lines.product.unit:id,code',
                'lines.unit:id,code',
                'takenBy:id,name',
                'cancelledBy:id,name',
                'sale:id,reference',
            ])
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(function (PendingPayment $pending) use ($controller, $request) {
                $detail = $controller->detail($request, $pending, withPaymentBox: false);

                return $detail['pending'] + ['can' => $detail['can']];
            })
            ->all();
    }

    /**
     * One day's sales, newest first.
     *
     * No cost and no profit, whatever the account may see elsewhere. This
     * screen faces a customer across a counter, and a margin on display is
     * a margin somebody can read upside down.
     *
     * The takings are counted over the whole day, not over the rows that
     * fit on screen. Summing the page would quietly understate a busy day
     * the moment it passed fifty sales — which is the kind of wrong figure
     * nobody notices until they are counting cash.
     *
     * @return array{rows: list<array<string, mixed>>, count: int, total: string, on_account: string, by_method: list<array{name:string, kind:string, amount:string}>, shown: int, of: int}
     */
    protected function salesOn(Carbon $date): array
    {
        $bounds = [$date->copy()->startOfDay(), $date->copy()->endOfDay()];

        $onTheDay = fn () => Sale::query()->whereBetween('sale_date', $bounds);

        // What was sold, over every sale of that day, and how much of it
        // went on account rather than being taken.
        $takings = $onTheDay()
            ->where('status', DocumentStatus::Posted->value)
            ->selectRaw('COUNT(*) AS documents')
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS total')
            ->selectRaw('COALESCE(SUM(on_account), 0) AS on_account')
            ->first();

        $sales = $onTheDay()
            ->with('customer:id,name')
            ->withCount('lines')
            ->orderByDesc('id')
            ->limit(self::ROWS)
            ->get(['id', 'reference', 'customer_id', 'status', 'total_amount', 'posted_at']);

        return [
            'rows' => $sales->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'reference' => $sale->reference,
                'customer' => $sale->customer?->name,
                'items' => $sale->lines_count,
                'total' => $sale->total_amount,
                'at' => $sale->posted_at?->format('g:i A'),
                'status' => $sale->status->value,
                'status_label' => $sale->status->label(),
                'status_tone' => $sale->status->tone(),
            ])->all(),

            'count' => (int) $takings->documents,
            'total' => number_format((float) $takings->total, 2, '.', ''),

            // Sold today but not taken: no drawer or app will show it.
            'on_account' => number_format((float) $takings->on_account, 2, '.', ''),

            // The same takings, by how they came in: what the drawer and
            // the GCash app should each show at the end of the day. Read
            // from the same figure the dashboard's Collections reads.
            // Sales only: a customer paying an old invoice is not today's takings.
            'by_method' => $this->metrics->collections($date, $date, accountPayments: false)['methods'] ?? [],

            // So the screen can say when it is not showing everything.
            'shown' => $sales->count(),
            'of' => $onTheDay()->count(),
        ];
    }

    /**
     * The sales waiting on hold.
     *
     * Bounded by `HeldSale::MAX_OPEN`, which the hold request enforces, so
     * this is never more than a screenful.
     *
     * @return list<array<string, mixed>>
     */
    protected function holds(): array
    {
        return HeldSale::query()
            ->with(['customer:id,name', 'heldBy:id,name'])
            ->orderByDesc('id')
            ->limit(HeldSale::MAX_OPEN)
            ->get()
            ->map(fn (HeldSale $hold) => [
                'id' => $hold->id,
                'note' => $hold->note,
                'customer' => $hold->customer?->name,
                'lines' => count($hold->lines ?? []),
                'items' => $hold->items(),
                'total' => $hold->total_amount,
                'held_by' => $hold->heldBy?->name,
                'at' => $hold->created_at?->isToday()
                    ? $hold->created_at->format('g:i A')
                    : $hold->created_at?->format('j M, g:i A'),
            ])
            ->all();
    }

    /**
     * The days of a month that saw a posted sale.
     *
     * @return list<string>
     */
    protected function tradedOn(Carbon $date): array
    {
        return Sale::query()
            ->where('status', DocumentStatus::Posted->value)
            ->whereBetween('sale_date', [
                $date->copy()->startOfMonth()->startOfDay(),
                $date->copy()->endOfMonth()->endOfDay(),
            ])
            ->selectRaw('SUBSTR(sale_date, 1, 10) AS day')
            ->groupBy('day')
            ->pluck('day')
            ->all();
    }

    /**
     * Which day the counter is looking at.
     *
     * Today unless asked otherwise, and never a day that has not happened:
     * there is nothing to show from tomorrow, and a till that offered it
     * would only invite somebody to wonder why it was empty.
     */
    protected function chosenDay(Request $request): Carbon
    {
        $validated = $request->validate([
            'date' => [
                'nullable',
                'date_format:Y-m-d',
                'before_or_equal:'.Carbon::today()->toDateString(),
            ],
        ]);

        return isset($validated['date'])
            ? Carbon::parse($validated['date'])
            : Carbon::today();
    }

    /**
     * The walk-in customer every till sale is recorded against.
     *
     * Looked up rather than assumed: a sale needs a customer, and failing
     * at the counter with "no customer" would be a poor way to find out
     * that somebody had switched it off.
     *
     * @return array{id: ?int, name: ?string}
     */
    protected function walkIn(): array
    {
        $customer = Customer::query()
            ->where('is_active', true)
            ->orderByDesc('is_walk_in')
            ->orderByRaw("CASE WHEN name LIKE 'Walk-in%' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first(['id', 'name']);

        return ['id' => $customer?->id, 'name' => $customer?->name];
    }

    /**
     * The places the till may sell from: the active stores (phase 31.4).
     * With none on the list, only the one it fell back to.
     *
     * @return list<array{value:int,label:string,type:string}>
     */
    protected function tillLocations(?Location $current): array
    {
        $stores = array_values(array_filter(
            Location::options(),
            fn (array $option) => $option['type'] === LocationType::Store->value,
        ));

        if ($stores === [] && $current) {
            return [['value' => $current->id, 'label' => $current->name, 'type' => $current->type->value]];
        }

        return $stores;
    }
}
