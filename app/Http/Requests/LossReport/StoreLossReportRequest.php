<?php

namespace App\Http\Requests\LossReport;

use App\Http\Requests\Adjustment\StoreAdjustmentRequest;
use App\Http\Requests\Concerns\ValidatesQuantities;
use App\Models\Location;
use App\Models\LossReport;
use App\Models\Product;
use App\Support\LossPhoto;
use App\Support\Quantity;
use App\Support\Reservations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Reporting a loss (phase 33): where, why, what and how many, with photos as
 * evidence.
 *
 * A report holds its goods aside, so it may only hold what is free there:
 * on hand at the location, less what customer orders and other reports hold.
 * `LossReportDesk::report()` asks again with the rows locked.
 */
class StoreLossReportRequest extends FormRequest
{
    use ValidatesQuantities;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'location_id' => [
                'required', 'integer',
                Rule::exists('locations', 'id')->where('is_active', true),
            ],
            'reason' => ['required', Rule::in(StoreAdjustmentRequest::LOSS_REASONS)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'uuid'],

            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => [
                'required', 'integer',
                Rule::exists('products', 'id')->where('is_active', true),
            ],
            'lines.*.quantity' => $this->quantityRules(),

            'photos' => ['nullable', 'array', 'max:'.LossPhoto::MAX_PER_REPORT],
            'photos.*' => LossPhoto::RULES,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'location_id.required' => 'Pick where the goods are.',
            'location_id.exists' => 'Pick a location that is still active.',
            'reason.required' => 'Say what happened to the goods.',
            'lines.*.product_id.exists' => 'Pick a product that is still active.',
            'photos.max' => 'A report may carry at most '.LossPhoto::MAX_PER_REPORT.' photos.',
            'photos.*.image' => 'A photo must be a picture: JPG, PNG or WebP.',
            'photos.*.mimes' => 'A photo must be a JPG, PNG or WebP picture.',
            'photos.*.max' => 'A photo may be at most 4 MB.',
            // The server refused the file before Laravel saw it: PHP's own
            // upload_max_filesize is below 4 MB (README, "Loss reports").
            'photos.*.uploaded' => 'This photo could not be uploaded. It may be bigger than the server allows: try a smaller one, at most 4 MB.',
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
                $this->askForAPhoto($validator);
                $this->refuseHoldingWhatIsNotFree($validator);
            },
        ];
    }

    /** Damaged, expired, rusted or written-off goods are there to photograph. */
    protected function askForAPhoto(Validator $validator): void
    {
        if (! (bool) settings('losses.photo_required', true)) {
            return;
        }

        if (! in_array($this->input('reason'), StoreAdjustmentRequest::PHOTOGRAPHABLE, true)) {
            return;
        }

        if (count($this->file('photos') ?? []) === 0) {
            $validator->errors()->add(
                'photos',
                'Add at least one photo of the goods: a loss reported as '
                    .strtolower(StoreAdjustmentRequest::REASONS[$this->input('reason')]).' needs one as evidence.'
            );
        }
    }

    /**
     * Lines naming the same product are added up first, and weighed against
     * what is free at the location.
     */
    protected function refuseHoldingWhatIsNotFree(Validator $validator): void
    {
        $wanted = [];

        foreach ($this->input('lines', []) as $index => $line) {
            $id = (int) ($line['product_id'] ?? 0);
            $wanted[$id]['quantity'] = ($wanted[$id]['quantity'] ?? 0) + Quantity::toMilli($line['quantity'] ?? 0);
            $wanted[$id]['lines'][] = $index;
        }

        $location = Location::find($this->integer('location_id'));

        if (! $location) {
            return;
        }

        $ids = array_keys($wanted);
        $there = Reservations::atLocation($ids, $location->id);
        $held = Reservations::reserved($ids, locationId: $location->id);
        $forLosses = Reservations::heldForLosses($ids, $location->id);
        $forPending = Reservations::heldForPendingPayments($ids, $location->id);

        foreach (Product::with('unit:id,code')->whereIn('id', $ids)->get(['id', 'sku', 'unit_id']) as $product) {
            $here = $there[$product->id] ?? 0;
            $taken = $held[$product->id] ?? 0;
            $free = max(0, $here - $taken);

            if ($wanted[$product->id]['quantity'] <= $free) {
                continue;
            }

            $code = $product->unit?->code ? ' '.$product->unit->code : '';
            $asked = Quantity::format(Quantity::fromMilli($wanted[$product->id]['quantity'])).$code;

            $message = $taken > 0
                ? 'Only '.Quantity::format(Quantity::fromMilli($free)).$code." of [{$product->sku}] at {$location->name} is free to report ("
                    .Reservations::describe($taken - ($forLosses[$product->id] ?? 0) - ($forPending[$product->id] ?? 0), $forLosses[$product->id] ?? 0, $code, $forPending[$product->id] ?? 0)
                    ."), and this reports {$asked}."
                : 'Only '.Quantity::format(Quantity::fromMilli($here)).$code." of [{$product->sku}] is on hand at {$location->name}, and this reports {$asked}.";

            foreach ($wanted[$product->id]['lines'] as $index) {
                $validator->errors()->add("lines.{$index}.quantity", $message);
            }
        }
    }

    public function alreadySaved(): ?LossReport
    {
        if (! $this->filled('idempotency_key')) {
            return null;
        }

        return LossReport::firstWhere('idempotency_key', $this->input('idempotency_key'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
        ]);
    }
}
