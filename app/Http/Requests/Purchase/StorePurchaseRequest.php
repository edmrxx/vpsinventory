<?php

namespace App\Http\Requests\Purchase;

use App\Http\Requests\Concerns\ValidatesQuantities;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Support\DeliveryPayment;
use App\Support\OrderReceiving;
use App\Support\Quantity;
use App\Support\UnitConversion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePurchaseRequest extends FormRequest
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
            // A document dated in the future would raise the floor for
            // everybody, because nothing may be dated before the last
            // posted document.
            'before_or_equal:'.Carbon::today()->toDateString(),
        ];

        if ($floor = $this->floorDate()) {
            $dateRules[] = 'after_or_equal:'.$floor;
        }

        return [
            'supplier_id' => [
                'required', 'integer',
                Rule::exists('suppliers', 'id')->where('is_active', true),
            ],
            'supplier_reference' => [
                'nullable', 'string', 'max:60',
                // The same invoice from the same supplier is the clearest
                // sign that a delivery is being booked twice.
                //
                // The purchase this very form already made is skipped,
                // because a double-clicked Save should land on it rather
                // than be told off for a duplicate it created itself.
                Rule::unique('purchases', 'supplier_reference')
                    ->where('supplier_id', $this->input('supplier_id'))
                    ->ignore($this->alreadySaved()),
            ],
            'purchase_date' => $dateRules,
            // Where the goods go on the shelf (phase 31.3): any active
            // location. The poster asks again under a lock. A form sent with
            // no such key at all — a tab opened before 31.3 — posts at the
            // default, as a sale with no payments key is paid in cash; one
            // sent with the box left empty is refused.
            'location_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('locations', 'id')->where('is_active', true),
            ],
            // The order this delivery fills, if any (phase 23). What it may
            // carry is weighed in after(), by OrderReceiving.
            'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // On the supplier's account rather than paid on delivery (phase 24).
            'on_account' => ['nullable', 'boolean'],
            // Phase 24.5: a down payment today, the rest on account; and how
            // whatever is paid today was paid. Weighed in after(), by
            // DeliveryPayment. No method key at all is cash.
            'paid_now' => ['nullable', 'numeric', 'max:999999999999.99'],
            'payment_method_id' => ['nullable', 'integer'],
            'payment_reference' => ['nullable', 'string', 'max:60'],
            'idempotency_key' => ['nullable', 'uuid'],

            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where('is_active', true),
            ],
            // The unit the line is typed in: a box of 100, or empty for
            // the product's own unit. Which units a product offers is
            // checked against its saved list in after().
            'lines.*.unit_id' => ['nullable', 'integer'],
            // The order line this line fills. Only with an order.
            'lines.*.purchase_order_line_id' => ['nullable', 'integer'],
            'lines.*.quantity' => $this->quantityRules(),
            // What one of the typed unit cost: per piece, or per box.
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier_id.exists' => 'Pick a supplier that is still active.',
            'location_id.required' => 'Pick where the goods are received.',
            'location_id.exists' => 'Pick a location that is still active.',
            'supplier_reference.unique' => 'This invoice number has already been recorded for this supplier.',
            'purchase_date.before_or_equal' => 'A purchase cannot be dated in the future.',
            'purchase_date.after_or_equal' => 'Stock is costed in the order it was posted, so this cannot be dated before the last posted document.',
            'lines.required' => 'Add at least one product to this purchase.',
            'lines.min' => 'Add at least one product to this purchase.',
            'lines.*.product_id.exists' => 'Pick a product that is still active.',
        ] + $this->quantityMessages();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || $this->alreadySaved()) {
                    return;
                }

                $resolved = $this->convertLines($validator);

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->filled('purchase_order_id')) {
                    $this->weighAgainstOrder($validator, $resolved);
                } elseif (collect($this->input('lines', []))->contains(fn ($line) => ! empty($line['purchase_order_line_id']))) {
                    $validator->errors()->add('purchase_order_id', 'A line is tied to an order, but no order is picked.');
                }

                foreach ($this->payment()['errors'] as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }

    /**
     * How this delivery is paid (phase 24.5), as `PurchasePoster` will weigh
     * it: the total line by line to the centavo, exactly as the poster prices
     * it.
     *
     * @return array{paid: int, on_account: int, payment_method_id: ?int, payment_reference: ?string, errors: array<string, string>}
     */
    public function payment(): array
    {
        $total = 0;

        foreach ($this->input('lines', []) as $line) {
            $total += Quantity::cents(Quantity::toMilli($line['quantity'] ?? 0), round((float) ($line['unit_cost'] ?? 0), 2));
        }

        return DeliveryPayment::weigh(
            $total,
            $this->boolean('on_account'),
            $this->input('paid_now'),
            $this->has('payment_method_id'),
            $this->input('payment_method_id'),
            $this->input('payment_reference'),
        );
    }

    /**
     * Put each refusal of the order under the line or box it is about.
     *
     * @param  array<int, array<string, mixed>>  $resolved  From convertLines().
     */
    protected function weighAgainstOrder(Validator $validator, array $resolved): void
    {
        $order = PurchaseOrder::with(['lines', 'lines.product.unit:id,code'])->find($this->integer('purchase_order_id'));

        $rows = [];

        foreach ($this->input('lines', []) as $index => $line) {
            $rows[$index] = [
                'order_line_id' => ! empty($line['purchase_order_line_id']) ? (int) $line['purchase_order_line_id'] : null,
                'product_id' => (int) ($line['product_id'] ?? 0),
                'milli' => $resolved[$index]['base'] ?? null,
            ];
        }

        $weighed = OrderReceiving::weigh($order, $this->integer('supplier_id'), $rows);

        foreach ($weighed['errors'] as $field => $message) {
            $validator->errors()->add($field, $message);
        }
    }

    /**
     * The purchase this exact form already made, found by the key it carries.
     *
     * A form is given one key when it is opened. Sending it twice — a double
     * click, a refresh, a flaky connection — must not make two documents.
     */
    public function alreadySaved(): ?Purchase
    {
        if (! $this->filled('idempotency_key')) {
            return null;
        }

        return Purchase::firstWhere('idempotency_key', $this->input('idempotency_key'));
    }

    /**
     * Is this form the very same one that made that purchase?
     *
     * Pressing Back brings the create screen out of the browser's history
     * with the key it was given the first time, and no request is made, so
     * the key alone cannot tell a double click from the next delivery being
     * typed into a stale page. The contents decide it.
     */
    public function matchesSaved(Purchase $purchase): bool
    {
        $sameHeader = (int) $this->input('supplier_id') === (int) $purchase->supplier_id
            && (int) $this->input('purchase_order_id') === (int) $purchase->purchase_order_id
            // A form with no location key posted at the default (phase 31.4
            // made the four documents compare it the same way).
            && (int) ($this->has('location_id') ? $this->integer('location_id') : Location::default()?->id) === (int) $purchase->location_id
            && (string) $this->input('supplier_reference') === (string) $purchase->supplier_reference
            && (string) $this->input('purchase_date') === $purchase->purchase_date?->toDateString()
            // How it was paid (phase 24.5): a retry paid another way is another delivery.
            && $this->payment()['on_account'] === Quantity::centavos((float) $purchase->on_account)
            && $this->payment()['payment_method_id'] === ($purchase->payment_method_id === null ? null : (int) $purchase->payment_method_id);

        if (! $sameHeader) {
            return false;
        }

        // Compared as typed: 5 boxes at 400, not 500 pieces at 4.
        $lines = $this->input('lines', []);
        $units = UnitConversion::resolve($lines);

        $sent = collect($lines)
            ->map(fn (array $line, $index) => [
                (int) ($line['product_id'] ?? 0),
                $units[$index]['unit_id'],
                Quantity::toMilli($line['quantity'] ?? 0),
                number_format((float) ($line['unit_cost'] ?? 0), 2, '.', ''),
            ])
            ->values()
            ->all();

        $saved = $purchase->lines()
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
     * The earliest date this document may carry: the day of the last posted
     * movement, because costs are taken in posting order.
     */
    public function floorDate(): ?string
    {
        $last = InventoryMovement::max('movement_date');

        return $last ? Carbon::parse($last)->toDateString() : null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'supplier_reference' => $this->filled('supplier_reference')
                ? strtoupper(trim((string) $this->input('supplier_reference')))
                : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
        ]);
    }
}
