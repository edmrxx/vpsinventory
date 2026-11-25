<?php

namespace App\Http\Requests\PurchaseReturn;

use App\Exceptions\InventoryException;
use App\Http\Requests\Concerns\ValidatesQuantities;
use App\Models\InventoryMovement;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Services\InventoryLedger;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePurchaseReturnRequest extends FormRequest
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

        return [
            'purchase_id' => [
                'required', 'integer',
                // Only a delivery that still stands can have goods go back.
                Rule::exists('purchases', 'id')->where('status', 'posted'),
            ],
            'return_date' => $dateRules,
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'uuid'],

            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.purchase_line_id' => ['required', 'integer', Rule::exists('purchase_lines', 'id')],
            'lines.*.quantity' => $this->quantityRules(zeroAllowed: true),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'purchase_id.exists' => 'That delivery cannot have goods returned against it.',
            'reason.required' => 'Say why these goods are going back.',
            'return_date.before_or_equal' => 'A return cannot be dated in the future.',
            'return_date.after_or_equal' => 'Stock is costed in the order it was posted, so this cannot be dated before the last posted document.',
        ] + $this->quantityMessages();
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // This form has already made a return, so the quantities it
                // asks for are spoken for. The controller decides what a
                // repeat means.
                if ($this->alreadySaved()) {
                    return;
                }

                $this->refuseAnEmptyReturn($validator);
                $this->refuseMoreThanIsStillHere($validator);
            },
        ];
    }

    protected function refuseAnEmptyReturn(Validator $validator): void
    {
        $total = collect($this->input('lines', []))->sum(fn (array $l) => Quantity::toMilli($l['quantity'] ?? 0));

        if ($total < 1) {
            $validator->errors()->add('lines', 'Enter how many of something are going back.');
        }
    }

    protected function refuseMoreThanIsStillHere(Validator $validator): void
    {
        $lines = PurchaseLine::with('product.unit:id,code,allows_decimals', 'layer')
            ->whereIn('id', collect($this->input('lines', []))->pluck('purchase_line_id'))
            ->where('purchase_id', $this->input('purchase_id'))
            ->get()
            ->keyBy('id');

        foreach ($this->input('lines', []) as $index => $line) {
            // In thousandths: 2.5 is 2500.
            $quantity = Quantity::toMilli($line['quantity'] ?? 0);

            if ($quantity < 1) {
                continue;
            }

            $purchaseLine = $lines[(int) $line['purchase_line_id']] ?? null;

            if (! $purchaseLine) {
                $validator->errors()->add("lines.{$index}.purchase_line_id", 'That line does not belong to this delivery.');

                continue;
            }

            if ($quantity % Quantity::SCALE !== 0 && ! $purchaseLine->product->unit?->allows_decimals) {
                $validator->errors()->add(
                    "lines.{$index}.quantity",
                    "[{$purchaseLine->product->sku}] is counted in whole numbers, so the quantity cannot have decimals."
                );

                continue;
            }

            if ($quantity > Quantity::toMilli($purchaseLine->returnable())) {
                $returnable = Quantity::format($purchaseLine->returnable());

                // Moved to another place by a transfer, not sold: a return
                // takes from the delivery's own batch only, and voiding the
                // transfer puts the goods back in it (phase 31.5).
                $transfers = $purchaseLine->inventory_layer_id
                    ? app(InventoryLedger::class)->transfersHolding([$purchaseLine->inventory_layer_id])
                    : [];

                $validator->errors()->add(
                    "lines.{$index}.quantity",
                    "Only {$returnable} of [{$purchaseLine->product->sku}] from this delivery is still on the shelf."
                        .InventoryException::movedByTransfer($transfers, 'return it')
                );
            }
        }
    }

    public function alreadySaved(): ?PurchaseReturn
    {
        if (! $this->filled('idempotency_key')) {
            return null;
        }

        return PurchaseReturn::firstWhere('idempotency_key', $this->input('idempotency_key'));
    }

    public function matchesSaved(PurchaseReturn $return): bool
    {
        if ((int) $this->input('purchase_id') !== (int) $return->purchase_id) {
            return false;
        }

        $sent = collect($this->input('lines', []))
            ->filter(fn (array $l) => Quantity::toMilli($l['quantity'] ?? 0) > 0)
            ->map(fn (array $l) => [(int) $l['purchase_line_id'], Quantity::toMilli($l['quantity'])])
            ->values()
            ->sort()
            ->values()
            ->all();

        $saved = $return->lines()
            ->orderBy('id')
            ->get(['purchase_line_id', 'quantity'])
            ->map(fn ($l) => [(int) $l->purchase_line_id, Quantity::toMilli($l->quantity)])
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

    /** The delivery this return is against, for the controller and screen. */
    public function purchase(): ?Purchase
    {
        return Purchase::find($this->input('purchase_id'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => trim((string) $this->input('reason')),
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
        ]);
    }
}
