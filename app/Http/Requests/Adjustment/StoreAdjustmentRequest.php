<?php

namespace App\Http\Requests\Adjustment;

use App\Http\Requests\Concerns\ValidatesQuantities;
use App\Models\InventoryAdjustment;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Support\Quantity;
use App\Support\Reservations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAdjustmentRequest extends FormRequest
{
    use ValidatesQuantities;

    /**
     * Why stock is being corrected. A free-text box alone explains nothing.
     *
     * The one list of reasons: a loss report (phase 33) offers
     * `LOSS_REASONS` out of it, and posts its write-off with the same key,
     * so the two can never drift. A key is never renamed — old documents
     * read it — only its label.
     */
    public const REASONS = [
        'damaged' => 'Damaged or spoiled',
        // Phase 33: expired, rusted and theft, asked for by name.
        'expired' => 'Expired',
        'rust' => 'Rusted or corroded',
        'theft' => 'Theft',
        // "Lost or stolen" until phase 33, when theft got its own reason.
        'lost' => 'Lost or missing',
        'count' => 'Stock count correction',
        // Stock still on the shelf that can no longer be sold: obsolete or
        // kept back as a sample. The dashboard counts it as a loss like the
        // others.
        'write_off' => 'Written off (obsolete or unsellable)',
        'opening' => 'Opening stock',
        'other' => 'Other',
    ];

    /** The reasons a loss report may give (phase 33): not a count, not opening stock. */
    public const LOSS_REASONS = ['damaged', 'expired', 'rust', 'theft', 'lost', 'write_off', 'other'];

    /**
     * The reasons where the goods are still there to photograph. A report
     * giving one of these needs a photo while `losses.photo_required` is on;
     * a theft or a missing item has nothing to show.
     */
    public const PHOTOGRAPHABLE = ['damaged', 'expired', 'rust', 'write_off'];

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
            'kind' => ['required', Rule::in([
                InventoryAdjustment::KIND_OPENING,
                InventoryAdjustment::KIND_INCREASE,
                InventoryAdjustment::KIND_DECREASE,
            ])],
            'adjustment_date' => $dateRules,
            // Where the stock is counted in or out (phase 31.4): any active
            // location. A form with no such key at all posts at the default;
            // an empty one is refused.
            'location_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('locations', 'id')->where('is_active', true),
            ],
            'reason' => ['required', Rule::in(array_keys(self::REASONS))],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'uuid'],

            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where('is_active', true),
            ],
            'lines.*.quantity' => $this->quantityRules(),
            // Stock coming in has to say what it cost, or the batch it
            // opens would silently take the product's own figure. Stock
            // going out is costed by the batches it eats, so it is never
            // typed.
            'lines.*.unit_cost' => [
                'required_unless:kind,'.InventoryAdjustment::KIND_DECREASE,
                'nullable', 'numeric', 'min:0', 'max:999999999999.99',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the stock is being corrected.',
            'location_id.required' => 'Pick where the stock is.',
            'location_id.exists' => 'Pick a location that is still active.',
            'adjustment_date.before_or_equal' => 'An adjustment cannot be dated in the future.',
            'adjustment_date.after_or_equal' => 'Stock is costed in the order it was posted, so this cannot be dated before the last posted document.',
            'lines.*.product_id.exists' => 'Pick a product that is still active.',
        ] + $this->quantityMessages();
    }

    public function isDecrease(): bool
    {
        return $this->input('kind') === InventoryAdjustment::KIND_DECREASE;
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

                $this->refusePartsOfWholeUnits($validator, $this->productIdsByLine());

                if (! $this->isDecrease()) {
                    return;
                }

                $this->refuseTakingWhatIsNotThere($validator);
            },
        ];
    }

    /**
     * Lines naming the same product are added up first, so two that each
     * look reasonable are still refused when together they are not.
     */
    protected function refuseTakingWhatIsNotThere(Validator $validator): void
    {
        $wanted = [];

        foreach ($this->input('lines', []) as $index => $line) {
            $id = (int) ($line['product_id'] ?? 0);
            // In thousandths, so 0.1 + 0.2 is weighed as exactly 0.3.
            $wanted[$id]['quantity'] = ($wanted[$id]['quantity'] ?? 0) + Quantity::toMilli($line['quantity'] ?? 0);
            $wanted[$id]['lines'][] = $index;
        }

        // Weighed at the location it comes out of (phase 31.4).
        $location = $this->adjustingLocation();

        if (! $location) {
            return;
        }

        $there = Reservations::atLocation(array_keys($wanted), $location->id);

        foreach (Product::whereIn('id', array_keys($wanted))->get(['id', 'sku', 'on_hand']) as $product) {
            $onHandMilli = $there[$product->id] ?? 0;

            if ($wanted[$product->id]['quantity'] <= $onHandMilli) {
                continue;
            }

            $asked = Quantity::format(Quantity::fromMilli($wanted[$product->id]['quantity']));
            $onHand = Quantity::format(Quantity::fromMilli($onHandMilli));
            $elsewhere = Quantity::toMilli($product->on_hand) - $onHandMilli;

            foreach ($wanted[$product->id]['lines'] as $index) {
                $validator->errors()->add(
                    "lines.{$index}.quantity",
                    "Only {$onHand} of [{$product->sku}] is on hand at {$location->name}, and this asks for {$asked}."
                        .($elsewhere > 0 ? ' '.Quantity::format(Quantity::fromMilli($elsewhere)).' more is at other locations.' : '')
                );
            }
        }
    }

    /**
     * Where the stock is counted in or out: the location sent, or the
     * default for a form that sent none (phase 31.4).
     */
    public function adjustingLocation(): ?Location
    {
        return $this->has('location_id')
            ? Location::find($this->integer('location_id'))
            : Location::default();
    }

    public function alreadySaved(): ?InventoryAdjustment
    {
        if (! $this->filled('idempotency_key')) {
            return null;
        }

        return InventoryAdjustment::firstWhere('idempotency_key', $this->input('idempotency_key'));
    }

    public function matchesSaved(InventoryAdjustment $adjustment): bool
    {
        $sameHeader = $this->input('kind') === $adjustment->kind
            && (int) $this->adjustingLocation()?->id === (int) $adjustment->location_id
            && (string) $this->input('adjustment_date') === $adjustment->adjustment_date?->toDateString();

        if (! $sameHeader) {
            return false;
        }

        $sent = collect($this->input('lines', []))
            ->map(fn (array $l) => [(int) $l['product_id'], Quantity::toMilli($l['quantity'])])
            ->all();

        $saved = $adjustment->lines()
            ->orderBy('id')
            ->get(['product_id', 'quantity'])
            ->map(fn ($l) => [(int) $l->product_id, Quantity::toMilli($l->quantity)])
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
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
        ]);
    }
}
