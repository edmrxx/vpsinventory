<?php

namespace App\Http\Requests\SalesReturn;

use App\Http\Requests\Concerns\ValidatesQuantities;
use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalesReturn;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSalesReturnRequest extends FormRequest
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
            'sale_id' => [
                'required', 'integer',
                // Only a sale that still stands can have goods come back.
                Rule::exists('sales', 'id')->where('status', 'posted'),
            ],
            'return_date' => $dateRules,
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'uuid'],

            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.sale_line_id' => ['required', 'integer', Rule::exists('sale_lines', 'id')],
            'lines.*.quantity' => $this->quantityRules(zeroAllowed: true),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sale_id.exists' => 'That sale cannot have goods returned against it.',
            'reason.required' => 'Say why these goods are coming back.',
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
                $this->refuseMoreThanWasSold($validator);
            },
        ];
    }

    protected function refuseAnEmptyReturn(Validator $validator): void
    {
        $total = collect($this->input('lines', []))->sum(fn (array $l) => Quantity::toMilli($l['quantity'] ?? 0));

        if ($total < 1) {
            $validator->errors()->add('lines', 'Enter how many of something are coming back.');
        }
    }

    protected function refuseMoreThanWasSold(Validator $validator): void
    {
        $lines = SaleLine::with('product.unit:id,code,allows_decimals')
            ->whereIn('id', collect($this->input('lines', []))->pluck('sale_line_id'))
            ->where('sale_id', $this->input('sale_id'))
            ->get()
            ->keyBy('id');

        foreach ($this->input('lines', []) as $index => $line) {
            // In thousandths: 2.5 is 2500.
            $quantity = Quantity::toMilli($line['quantity'] ?? 0);

            if ($quantity < 1) {
                continue;
            }

            $saleLine = $lines[(int) $line['sale_line_id']] ?? null;

            if (! $saleLine) {
                $validator->errors()->add("lines.{$index}.sale_line_id", 'That line does not belong to this sale.');

                continue;
            }

            if ($quantity % Quantity::SCALE !== 0 && ! $saleLine->product->unit?->allows_decimals) {
                $validator->errors()->add(
                    "lines.{$index}.quantity",
                    "[{$saleLine->product->sku}] is counted in whole numbers, so the quantity cannot have decimals."
                );

                continue;
            }

            if ($quantity > Quantity::toMilli($saleLine->returnable())) {
                $returnable = Quantity::format($saleLine->returnable());

                $validator->errors()->add(
                    "lines.{$index}.quantity",
                    "Only {$returnable} of [{$saleLine->product->sku}] can still come back from this sale."
                );
            }
        }
    }

    public function alreadySaved(): ?SalesReturn
    {
        if (! $this->filled('idempotency_key')) {
            return null;
        }

        return SalesReturn::firstWhere('idempotency_key', $this->input('idempotency_key'));
    }

    public function matchesSaved(SalesReturn $return): bool
    {
        if ((int) $this->input('sale_id') !== (int) $return->sale_id) {
            return false;
        }

        $sent = collect($this->input('lines', []))
            ->filter(fn (array $l) => Quantity::toMilli($l['quantity'] ?? 0) > 0)
            ->map(fn (array $l) => [(int) $l['sale_line_id'], Quantity::toMilli($l['quantity'])])
            ->values()
            ->sort()
            ->values()
            ->all();

        $saved = $return->lines()
            ->orderBy('id')
            ->get(['sale_line_id', 'quantity'])
            ->map(fn ($l) => [(int) $l->sale_line_id, Quantity::toMilli($l->quantity)])
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

    /** The sale this return is against, for the controller and the screen. */
    public function sale(): ?Sale
    {
        return Sale::find($this->input('sale_id'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => trim((string) $this->input('reason')),
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
        ]);
    }
}
