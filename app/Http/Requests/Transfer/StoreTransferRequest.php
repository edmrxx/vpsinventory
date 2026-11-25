<?php

namespace App\Http\Requests\Transfer;

use App\Http\Requests\Concerns\ValidatesQuantities;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Support\Quantity;
use App\Support\Reservations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A transfer (phase 31.5): two different active locations, lines in the
 * product's own unit, and never more than is free at the source.
 *
 * Unlike a sale or a delivery there is no "no location key posts at the
 * default" rule: the form is new, so no tab opened before it exists, and a
 * transfer means nothing without both places.
 */
class StoreTransferRequest extends FormRequest
{
    use ValidatesQuantities;

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

        $active = Rule::exists('locations', 'id')->where('is_active', true);

        return [
            'transfer_date' => $dateRules,
            'from_location_id' => ['required', 'integer', $active],
            'to_location_id' => ['required', 'integer', $active, 'different:from_location_id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'uuid'],

            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where('is_active', true),
            ],
            'lines.*.quantity' => $this->quantityRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from_location_id.required' => 'Pick where the goods are now.',
            'from_location_id.exists' => 'Pick a location that is still active.',
            'to_location_id.required' => 'Pick where the goods are going.',
            'to_location_id.exists' => 'Pick a location that is still active.',
            'to_location_id.different' => 'Pick a destination other than where the goods are now.',
            'transfer_date.before_or_equal' => 'A transfer cannot be dated in the future.',
            'transfer_date.after_or_equal' => 'Stock is costed in the order it was posted, so this cannot be dated before the last posted document.',
            'lines.required' => 'Add at least one product to move.',
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

                $this->refusePartsOfWholeUnits($validator, $this->productIdsByLine());
                $this->refuseMovingWhatIsNotFree($validator);
            },
        ];
    }

    /**
     * Weighed at the source: what is there, less what customer orders hold
     * there. Lines naming the same product are added up first, in
     * thousandths.
     */
    protected function refuseMovingWhatIsNotFree(Validator $validator): void
    {
        $wanted = [];

        foreach ($this->input('lines', []) as $index => $line) {
            $id = (int) ($line['product_id'] ?? 0);
            $wanted[$id]['quantity'] = ($wanted[$id]['quantity'] ?? 0) + Quantity::toMilli($line['quantity'] ?? 0);
            $wanted[$id]['lines'][] = $index;
        }

        $from = Location::find($this->integer('from_location_id'));

        if (! $from) {
            return;
        }

        $ids = array_keys($wanted);
        $there = Reservations::atLocation($ids, $from->id);
        $held = Reservations::reserved($ids, locationId: $from->id);
        $forLosses = Reservations::heldForLosses($ids, $from->id);
        $forPending = Reservations::heldForPendingPayments($ids, $from->id);

        foreach (Product::whereIn('id', $ids)->get(['id', 'sku', 'on_hand']) as $product) {
            $here = $there[$product->id] ?? 0;
            $reserved = $held[$product->id] ?? 0;
            $free = max(0, $here - $reserved);

            if ($wanted[$product->id]['quantity'] <= $free) {
                continue;
            }

            $asked = Quantity::format(Quantity::fromMilli($wanted[$product->id]['quantity']));
            $elsewhere = Quantity::toMilli($product->on_hand) - $here;

            $message = $reserved > 0
                ? 'Only '.Quantity::format(Quantity::fromMilli($free))." of [{$product->sku}] at {$from->name} is free to move — "
                    .Reservations::describe($reserved - ($forLosses[$product->id] ?? 0) - ($forPending[$product->id] ?? 0), $forLosses[$product->id] ?? 0, '', $forPending[$product->id] ?? 0).' there — '
                    ."and this asks for {$asked}."
                : 'Only '.Quantity::format(Quantity::fromMilli($here))." of [{$product->sku}] is on hand at {$from->name}, and this asks for {$asked}.";

            if ($elsewhere > 0) {
                $message .= ' '.Quantity::format(Quantity::fromMilli($elsewhere)).' more is at other locations.';
            }

            foreach ($wanted[$product->id]['lines'] as $index) {
                $validator->errors()->add("lines.{$index}.quantity", $message);
            }
        }
    }

    public function alreadySaved(): ?StockTransfer
    {
        if (! $this->filled('idempotency_key')) {
            return null;
        }

        return StockTransfer::firstWhere('idempotency_key', $this->input('idempotency_key'));
    }

    public function matchesSaved(StockTransfer $transfer): bool
    {
        $sameHeader = (int) $this->input('from_location_id') === (int) $transfer->from_location_id
            && (int) $this->input('to_location_id') === (int) $transfer->to_location_id
            && (string) $this->input('transfer_date') === $transfer->transfer_date?->toDateString();

        if (! $sameHeader) {
            return false;
        }

        $sent = collect($this->input('lines', []))
            ->map(fn (array $l) => [(int) ($l['product_id'] ?? 0), Quantity::toMilli($l['quantity'] ?? 0)])
            ->all();

        $saved = $transfer->lines()
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
