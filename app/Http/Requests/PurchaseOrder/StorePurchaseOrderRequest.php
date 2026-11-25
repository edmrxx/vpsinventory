<?php

namespace App\Http\Requests\PurchaseOrder;

use App\Enums\PurchaseRequestStatus;
use App\Http\Requests\Concerns\ValidatesQuantities;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Support\Quantity;
use App\Support\UnitConversion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A purchase order: who supplies it, when it is expected, and the lines at
 * the cost agreed.
 *
 * The lines follow the purchase form's rules, so the delivery posted against
 * the order in phase 23 can copy them. No stock moves, so the posting-order
 * floor does not apply: an order may be dated any day up to today.
 */
class StorePurchaseOrderRequest extends FormRequest
{
    use ValidatesQuantities;

    /** How many lines one order may carry. */
    public const MAX_LINES = 200;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => [
                'required', 'integer',
                Rule::exists('suppliers', 'id')->where('is_active', 1),
            ],
            'purchase_request_id' => [
                'nullable', 'integer',
                Rule::exists('purchase_requests', 'id'),
            ],
            'order_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.Carbon::today()->toDateString()],
            'expected_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            // Where the supplier is to deliver (phase 31.3). Optional.
            'deliver_to_location_id' => [
                'nullable', 'integer',
                Rule::exists('locations', 'id')->where('is_active', true),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'uuid'],

            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where('is_active', 1),
            ],
            'lines.*.unit_id' => ['nullable', 'integer'],
            'lines.*.quantity' => $this->quantityRules(),
            // Agreed with the supplier, per typed unit: per piece, or per box.
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier_id.required' => 'Pick the supplier this order goes to.',
            'supplier_id.exists' => 'Pick a supplier that is still active.',
            'order_date.before_or_equal' => 'An order cannot be dated in the future.',
            'expected_date.after_or_equal' => 'The goods cannot be expected before the order is placed.',
            'deliver_to_location_id.exists' => 'Pick a location that is still active.',
            'lines.required' => 'Add at least one product to this order.',
            'lines.min' => 'Add at least one product to this order.',
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

                if ($this->filled('purchase_request_id')) {
                    $this->checkRequest($validator);
                }

                $this->convertLines($validator);
            },
        ];
    }

    /** Only an approved request, not yet ordered, becomes an order. */
    protected function checkRequest(Validator $validator): void
    {
        $id = $this->integer('purchase_request_id');

        if ($ordered = PurchaseOrder::where('purchase_request_id', $id)->value('reference')) {
            $validator->errors()->add('purchase_request_id', "This request was already ordered on [{$ordered}].");

            return;
        }

        // Read through the model, so the status arrives as the enum.
        if (PurchaseRequest::find($id)?->status !== PurchaseRequestStatus::Approved) {
            $validator->errors()->add('purchase_request_id', 'Only an approved request can become an order.');
        }
    }

    /** The order this exact form already placed, found by its key. */
    public function alreadySaved(): ?PurchaseOrder
    {
        if (! $this->filled('idempotency_key')) {
            return null;
        }

        return PurchaseOrder::firstWhere('idempotency_key', $this->input('idempotency_key'));
    }

    /** Is this form the very same one that placed that order? */
    public function matchesSaved(PurchaseOrder $order): bool
    {
        $sameHeader = (int) $this->input('supplier_id') === (int) $order->supplier_id
            && (string) $this->input('order_date') === $order->order_date?->toDateString()
            && (int) $this->input('purchase_request_id') === (int) $order->purchase_request_id
            && (int) $this->input('deliver_to_location_id') === (int) $order->deliver_to_location_id;

        if (! $sameHeader) {
            return false;
        }

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

        $saved = $order->lines()
            ->orderBy('id')
            ->get(['product_id', 'unit_id', 'unit_quantity', 'unit_cost'])
            ->map(fn ($line) => [
                (int) $line->product_id,
                $line->unit_id === null ? null : (int) $line->unit_id,
                Quantity::toMilli($line->unit_quantity),
                number_format((float) $line->unit_cost, 2, '.', ''),
            ])
            ->all();

        return $sent === $saved;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'purchase_request_id' => $this->filled('purchase_request_id') ? $this->input('purchase_request_id') : null,
            'expected_date' => $this->filled('expected_date') ? $this->input('expected_date') : null,
            'deliver_to_location_id' => $this->filled('deliver_to_location_id') ? $this->input('deliver_to_location_id') : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
        ]);
    }
}
