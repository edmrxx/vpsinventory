<?php

namespace App\Http\Requests\Sale;

use App\Exceptions\PendingPaymentException;
use App\Http\Requests\Concerns\ValidatesQuantities;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\PendingPayment;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SalesOrder;
use App\Services\PendingPaymentDesk;
use App\Support\CustomerCredit;
use App\Support\PaymentSettlement;
use App\Support\Quantity;
use App\Support\Reservations;
use App\Support\SaleTotals;
use App\Support\UnitConversion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSaleRequest extends FormRequest
{
    use ValidatesQuantities;

    /** How many lines one document may carry. */
    public const MAX_LINES = 200;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $dateRules = [
            'required', 'date_format:Y-m-d',
            'before_or_equal:'.Carbon::today()->toDateString(),
        ];

        if ($floor = $this->floorDate()) {
            $dateRules[] = 'after_or_equal:'.$floor;
        }

        return [
            'customer_id' => [
                'required', 'integer',
                Rule::exists('customers', 'id')->where('is_active', true),
            ],
            'sale_date' => $dateRules,
            // Where the goods leave the shelf (phase 31.4): any active
            // location. A form with no such key at all — a tab opened before
            // this phase — sells from the default; an empty one is refused.
            'location_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('locations', 'id')->where('is_active', true),
            ],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // The quotation this sale is made from (phase 28), if any.
            'quotation_id' => ['nullable', 'integer', 'exists:quotations,id'],
            // Or the sales order it fulfils (phase 29) — never both.
            'sales_order_id' => ['nullable', 'integer', 'exists:sales_orders,id', 'prohibits:quotation_id'],
            // Or the pending payment the kiosk sent (CR-2) that this sale
            // pays — never with either of the others.
            'pending_payment_id' => ['nullable', 'integer', 'exists:pending_payments,id', 'prohibits:quotation_id,sales_order_id'],
            'idempotency_key' => ['nullable', 'uuid'],
            // Which screen rang it up. Changes where the reply goes and
            // nothing else: the sale posted is the same either way.
            'from' => ['nullable', 'string', 'in:pos'],

            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where('is_active', true),
            ],
            // The unit the line is sold in: a box of 100, or empty for the
            // product's own unit. Checked against the product's saved list
            // in after().
            'lines.*.unit_id' => ['nullable', 'integer'],
            'lines.*.quantity' => $this->quantityRules(),
            // The price of one of the unit sold: per piece, or per box.
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],

            // How it was paid. Left out altogether, the sale is taken as
            // paid exactly in cash; sent, it has to cover the total. Both
            // sale screens always send it. See PaymentSettlement.
            'payments' => ['sometimes', 'array', 'max:'.PaymentSettlement::MAX],
            'payments.*.payment_method_id' => [
                'required', 'integer',
                Rule::exists('payment_methods', 'id')->where('is_active', true),
            ],
            // On cash, what was handed over; the change is worked out. Zero
            // is let through here because a sale given away (a full
            // discount) owes nothing, and both screens still send their
            // cash box at 0.00. PaymentSettlement refuses a zero payment
            // on any sale that does owe something.
            'payments.*.amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],

            // How much goes on the customer's account instead of being paid
            // now. Never inferred from a payment that falls short.
            'on_account' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            // The days agreed on this sale for what goes on account (phase
            // 18.1). Left out, or empty, the customer's usual terms; read
            // only when something goes on account.
            'terms_days' => ['nullable', 'integer', 'min:0', 'max:'.CustomerCredit::MAX_TERMS_DAYS],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_id.exists' => 'Pick a customer that is still active.',
            'location_id.required' => 'Pick where the goods are sold from.',
            'location_id.exists' => 'Pick a location that is still active.',
            'sale_date.before_or_equal' => 'A sale cannot be dated in the future.',
            'sale_date.after_or_equal' => 'Stock is costed in the order it was posted, so this cannot be dated before the last posted document.',
            'lines.required' => 'Add at least one product to this sale.',
            'lines.min' => 'Add at least one product to this sale.',
            'lines.*.product_id.exists' => 'Pick a product that is still active.',
            'payments.max' => 'A sale may be paid at most '.PaymentSettlement::MAX.' ways.',
            'payments.*.payment_method_id.required' => 'Pick how this was paid.',
            'payments.*.payment_method_id.exists' => 'Pick a payment method that is still active.',
            'payments.*.amount.required' => 'Type the amount paid.',
            'payments.*.amount.min' => 'An amount cannot be below zero.',
            'payments.*.amount.decimal' => 'An amount has at most 2 decimals.',
            'payments.*.reference.max' => 'A reference number is at most 100 characters.',
            'on_account.min' => 'An amount cannot be below zero.',
            'on_account.decimal' => 'An amount has at most 2 decimals.',
            'terms_days.integer' => 'Type the terms as a whole number of days.',
            'terms_days.min' => 'Terms cannot be below zero.',
            'terms_days.max' => 'Terms are at most '.CustomerCredit::MAX_TERMS_DAYS.' days.',
        ] + $this->quantityMessages();
    }

    /**
     * A discount cannot be more than the goods came to, and a line cannot
     * ask for more stock than there is.
     *
     * The stock check is done here as well as in the ledger, because here it
     * can be put against the line that caused it. The ledger is still the
     * one that decides: between this check and the posting, somebody else
     * may have sold the last of it.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // This form has already made a sale, so the stock it asked
                // for is gone — taken by that very sale. Checking again would
                // report "none on hand" for a sale that went through, and
                // send the user back to an empty form believing it failed.
                // The controller decides what to do with a repeat.
                if ($this->alreadySaved()) {
                    return;
                }

                $this->refuseADeadQuotation($validator);
                $this->refuseAnOrderNotReady($validator);
                $converted = $this->convertLines($validator);
                $this->refuseAPendingPaymentNotReady($validator);
                $this->refuseDiscountLargerThanTheSale($validator);
                $this->refuseSellingWhatIsNotThere($validator, $converted);
                $this->refuseWhatIsNotPaid($validator);
            },
        ];
    }

    /**
     * A quotation becomes a sale only while it is open, within its date and
     * for the same customer. `SalePoster` asks again with it locked.
     */
    protected function refuseADeadQuotation(Validator $validator): void
    {
        if (! $this->filled('quotation_id')) {
            return;
        }

        $quotation = Quotation::find($this->integer('quotation_id'));
        $date = Carbon::parse($this->input('sale_date'));

        if (! $quotation->isLive($date)) {
            $validator->errors()->add('quotation_id', "Quotation [{$quotation->reference}] is "
                .strtolower($quotation->standing($date)['label']).', so it cannot become a sale. Quote it again instead.');
        } elseif ((int) $quotation->customer_id !== $this->integer('customer_id')) {
            $validator->errors()->add('customer_id', "Quotation [{$quotation->reference}] was made for another customer.");
        }
    }

    /** Only an approved order, for the same customer, becomes a sale. */
    protected function refuseAnOrderNotReady(Validator $validator): void
    {
        if (! $this->filled('sales_order_id')) {
            return;
        }

        $order = SalesOrder::find($this->integer('sales_order_id'));

        if (! $order->isApproved()) {
            $validator->errors()->add('sales_order_id', "Order [{$order->reference}] is "
                .strtolower($order->standing()['label']).'. Only an approved order becomes a sale.');
        } elseif ((int) $order->customer_id !== $this->integer('customer_id')) {
            $validator->errors()->add('customer_id', "Order [{$order->reference}] was placed by another customer.");
        }
    }

    /**
     * A pending payment (CR-2) is paid only while it waits, for its own
     * customer, at its own store, and exactly as the kiosk sent it.
     * `SalePoster` asks again with it locked.
     */
    protected function refuseAPendingPaymentNotReady(Validator $validator): void
    {
        if (! $this->filled('pending_payment_id') || $validator->errors()->isNotEmpty()) {
            return;
        }

        $pending = PendingPayment::with('location:id,name')->find($this->integer('pending_payment_id'));

        if (! $pending->isWaiting()) {
            $validator->errors()->add('pending_payment_id', PendingPaymentException::notWaiting($pending->reference, $pending->standing()['label'])->getMessage());
        } elseif ((int) $pending->customer_id !== $this->integer('customer_id')) {
            $validator->errors()->add('pending_payment_id', PendingPaymentException::otherCustomer($pending->reference)->getMessage());
        } elseif ((int) $pending->location_id !== (int) $this->sellingLocation()?->id) {
            $validator->errors()->add('pending_payment_id', PendingPaymentException::otherPlace($pending->reference, (string) $pending->location?->name)->getMessage());
        } elseif (! PendingPaymentDesk::sameLines($pending, array_values($this->input('lines', [])), UnitConversion::resolve(array_values($this->input('lines', []))), $this->input('discount', 0))) {
            $validator->errors()->add('pending_payment_id', PendingPaymentException::linesChanged($pending->reference)->getMessage());
        }
    }

    protected function refuseDiscountLargerThanTheSale(Validator $validator): void
    {
        // Line by line to the centavo, exactly as SalePoster adds it up.
        $subtotal = SaleTotals::of($this->input('lines', []), 0)['subtotal'];

        if (Quantity::centavos(round((float) $this->input('discount', 0), 2)) > $subtotal) {
            $validator->errors()->add(
                'discount',
                'The discount cannot be more than the goods come to ('.number_format($subtotal / 100, 2).').'
            );
        }
    }

    /**
     * The payments have to settle the sale: what the poster will ask again,
     * asked here so the error sits under the box that caused it.
     */
    protected function refuseWhatIsNotPaid(Validator $validator): void
    {
        if ($validator->errors()->has('discount')) {
            return;
        }

        $total = SaleTotals::of($this->input('lines', []), $this->input('discount', 0))['total'];
        $onAccount = $this->onAccountCents();
        $paid = PaymentSettlement::settleSale($total, $onAccount, $this->paymentsSent());

        foreach ($paid['errors'] as $field => $message) {
            $validator->errors()->add($field, $message);
        }

        if ($onAccount > 0 && ! $validator->errors()->has('on_account')) {
            $customer = Customer::findOrFail($this->integer('customer_id'));
            $refusal = CustomerCredit::refusal($customer, $onAccount);

            if ($refusal !== null) {
                $validator->errors()->add('on_account', $refusal);
            }

            if ($this->termsSent() !== null) {
                $terms = CustomerCredit::termsRefusal($customer, $this->termsSent(), $this->mayExtendTerms());

                if ($terms !== null) {
                    $validator->errors()->add('terms_days', $terms);
                }
            }
        }
    }

    protected function onAccountCents(): int
    {
        return Quantity::centavos(round((float) $this->input('on_account', 0), 2));
    }

    /**
     * The days agreed on this sale, or null for the customer's usual terms:
     * a form from before phase 18.1 sends none, and an empty box is the
     * same.
     */
    public function termsSent(): ?int
    {
        return $this->filled('terms_days') ? $this->integer('terms_days') : null;
    }

    /** May this user give more days than the customer's usual terms? */
    public function mayExtendTerms(): bool
    {
        return (bool) $this->user()?->can('customers.credit');
    }

    /**
     * The payments as sent, or null when the form sent none at all.
     *
     * @return list<array<string, mixed>>|null
     */
    public function paymentsSent(): ?array
    {
        if (! $this->has('payments')) {
            return null;
        }

        $payments = $this->input('payments');

        return is_array($payments) ? array_values($payments) : [];
    }

    /**
     * Several lines may name the same product, so the quantities are added
     * up per product before they are weighed against what is on hand.
     *
     * Weighed in the product's own unit: a box of 100 and 5 loose pieces
     * ask the shelf for 105 pieces.
     *
     * @param  array<int, array{base:?int}>  $converted  From convertLines().
     */
    protected function refuseSellingWhatIsNotThere(Validator $validator, array $converted): void
    {
        $wanted = [];

        foreach ($this->input('lines', []) as $index => $line) {
            // A line that did not convert already carries its own error.
            if (($converted[$index]['base'] ?? null) === null) {
                continue;
            }

            $id = (int) ($line['product_id'] ?? 0);
            // In thousandths, so 0.1 + 0.2 is weighed as exactly 0.3.
            $wanted[$id]['quantity'] = ($wanted[$id]['quantity'] ?? 0) + $converted[$index]['base'];
            $wanted[$id]['lines'][] = $index;
        }

        if ($wanted === []) {
            return;
        }

        $products = Product::with('unit:id,code')->whereIn('id', array_keys($wanted))->get(['id', 'sku', 'on_hand', 'unit_id']);

        // Weighed at the location the goods leave from (phase 31.4): what
        // is there, less what customer orders hold there.
        $location = $this->sellingLocation();

        if (! $location) {
            return;
        }

        $there = Reservations::atLocation(array_keys($wanted), $location->id);

        // What is reserved for customer orders cannot be sold to anybody
        // else (phase 29) — except the order this sale fulfils, whose own
        // reservation it may take.
        // So may the sale paying a pending payment (CR-2) take what that
        // order holds.
        $held = Reservations::reserved(
            array_keys($wanted),
            $this->integer('sales_order_id') ?: null,
            locationId: $location->id,
            exceptPending: $this->integer('pending_payment_id') ?: null,
        );
        // The parts of that held for loss reports (phase 33) and waiting at
        // the cashier (CR-2), so the message says which is which.
        $forLosses = Reservations::heldForLosses(array_keys($wanted), $location->id);
        $forPending = Reservations::heldForPendingPayments(array_keys($wanted), $location->id, exceptPending: $this->integer('pending_payment_id') ?: null);

        foreach ($products as $product) {
            $reserved = $held[$product->id] ?? 0;
            $onHandMilli = $there[$product->id] ?? 0;
            $free = max(0, $onHandMilli - $reserved);

            if ($wanted[$product->id]['quantity'] <= $free) {
                continue;
            }

            // Named in the unit the stock is counted in, because a box of
            // 100 asked for against 60 on hand is about pieces.
            $code = $product->unit?->code ? ' '.$product->unit->code : '';
            $asked = Quantity::format(Quantity::fromMilli($wanted[$product->id]['quantity'])).$code;
            $onHand = Quantity::format(Quantity::fromMilli($onHandMilli)).$code;
            $elsewhere = Quantity::toMilli($product->on_hand) - $onHandMilli;
            $message = $reserved > 0
                ? 'Only '.Quantity::format(Quantity::fromMilli($free)).$code." of [{$product->sku}] is free to sell at {$location->name} ({$onHand} on hand, "
                    .Reservations::describe($reserved - ($forLosses[$product->id] ?? 0) - ($forPending[$product->id] ?? 0), $forLosses[$product->id] ?? 0, $code, $forPending[$product->id] ?? 0)."), and this sale asks for {$asked}."
                : "Only {$onHand} of [{$product->sku}] is on hand at {$location->name}, and this sale asks for {$asked}.";

            // Enough on the books, but somewhere else: say so.
            if ($elsewhere > 0) {
                $message .= ' '.Quantity::format(Quantity::fromMilli($elsewhere)).$code.' more is at other locations.';
            }

            // Put against every line naming that product, so the person sees
            // it where they typed it.
            foreach ($wanted[$product->id]['lines'] as $index) {
                $validator->errors()->add("lines.{$index}.quantity", $message);
            }
        }
    }

    /**
     * Where this sale sells from: the location sent, or the default for a
     * form that sent none (phase 31.4). Null when the one sent is not there.
     */
    public function sellingLocation(): ?Location
    {
        return $this->has('location_id')
            ? Location::find($this->integer('location_id'))
            : Location::default();
    }

    public function alreadySaved(): ?Sale
    {
        if (! $this->filled('idempotency_key')) {
            return null;
        }

        return Sale::firstWhere('idempotency_key', $this->input('idempotency_key'));
    }

    /** Does this form hold the very same sale that key already made? */
    public function matchesSaved(Sale $sale): bool
    {
        $sameHeader = (int) $this->input('customer_id') === (int) $sale->customer_id
            && (int) $this->sellingLocation()?->id === (int) $sale->location_id
            && (string) $this->input('sale_date') === $sale->sale_date?->toDateString()
            && number_format((float) $this->input('discount', 0), 2, '.', '') === number_format((float) $sale->discount, 2, '.', '')
            && $this->onAccountCents() === Quantity::centavos((float) $sale->on_account);

        // The days agreed (phase 18.1): a retry with other terms is another
        // sale from the cashier's point of view. None sent means the usual.
        if ($sameHeader && $this->onAccountCents() > 0) {
            $terms = $this->termsSent() ?? (int) $sale->customer?->payment_terms_days;
            $sameHeader = $terms === (int) $sale->terms_days;
        }

        // The pending payment it paid (CR-2), if any.
        if ($sameHeader) {
            $paid = PendingPayment::where('sale_id', $sale->id)->value('id');
            $sameHeader = (int) $paid === $this->integer('pending_payment_id');
        }

        if (! $sameHeader || ! $this->samePayments($sale)) {
            return false;
        }

        // Compared as typed: 1 box at 450, not 100 pieces.
        $lines = $this->input('lines', []);
        $units = UnitConversion::resolve($lines);

        $sent = collect($lines)
            ->map(fn (array $line, $index) => [
                (int) ($line['product_id'] ?? 0),
                $units[$index]['unit_id'],
                Quantity::toMilli($line['quantity'] ?? 0),
                number_format((float) ($line['unit_price'] ?? 0), 2, '.', ''),
            ])
            ->values()
            ->all();

        $saved = $sale->lines()
            ->orderBy('id')
            ->get(['product_id', 'unit_id', 'unit_quantity', 'unit_price'])
            ->map(fn ($line) => [
                (int) $line->product_id,
                $line->unit_id === null ? null : (int) $line->unit_id,
                Quantity::toMilli($line->unit_quantity),
                number_format((float) $line->unit_price, 2, '.', ''),
            ])
            ->all();

        return $sent === $saved;
    }

    /**
     * Were the payments the same too?
     *
     * A retry with a corrected GCash number is a different sale from the
     * cashier's point of view; sending them to the old one would leave them
     * believing the new number was saved. Compared as typed: on cash, what
     * was handed over.
     */
    protected function samePayments(Sale $sale): bool
    {
        $sent = $this->paymentsSent();

        // Sent without payments, as every sale was before they were
        // recorded: nothing to compare.
        if ($sent === null) {
            return true;
        }

        $typed = fn ($method, $amount, $reference) => [
            (int) $method,
            Quantity::centavos(round((float) $amount, 2)),
            trim((string) $reference),
        ];

        // A box left at 0.00 stored nothing, as on a sale wholly on account.
        $sent = collect($sent)
            ->map(fn (array $p) => $typed($p['payment_method_id'] ?? 0, $p['amount'] ?? 0, $p['reference'] ?? ''))
            ->reject(fn (array $p) => $p[1] === 0)
            ->sort()
            ->values()
            ->all();

        $saved = $sale->payments()
            ->get(['payment_method_id', 'amount', 'tendered', 'reference'])
            ->map(fn ($p) => $typed($p->payment_method_id, $p->tendered ?? $p->amount, $p->reference ?? ''))
            ->sort()
            ->values()
            ->all();

        return $sent === $saved;
    }

    public function floorDate(): ?string
    {
        $last = InventoryMovement::max('movement_date');

        return $last ? Carbon::parse($last)->toDateString() : null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'discount' => $this->filled('discount') ? $this->input('discount') : 0,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
        ]);
    }
}
