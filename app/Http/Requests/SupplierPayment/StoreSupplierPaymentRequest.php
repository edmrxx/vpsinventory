<?php

namespace App\Http\Requests\SupplierPayment;

use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\SupplierPayment;
use App\Support\BillAllocation;
use App\Support\PaymentSettlement;
use App\Support\Quantity;
use App\Support\SupplierAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Money paid to a supplier, the credit used with it, and the bills it pays
 * (phase 24).
 *
 * Every rule that weighs the payment against the bills is asked of
 * `BillAllocation`, and the method of `PaymentSettlement`, so the error lands
 * under the row that caused it. `SupplierPaymentPoster` asks both again with
 * the rows locked, and decides.
 */
class StoreSupplierPaymentRequest extends FormRequest
{
    /** How many bills one payment may pay. */
    public const MAX_BILLS = 200;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // An inactive supplier may still be paid what it is owed.
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.Carbon::today()->toDateString()],
            // Needed only when money goes out; checked in after().
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')->where('is_active', 1)],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'credit_used' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'method_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'uuid'],

            'allocations' => ['required', 'array', 'min:1', 'max:'.self::MAX_BILLS],
            'allocations.*.purchase_id' => ['required', 'integer'],
            'allocations.*.amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier_id.required' => 'Pick the supplier being paid.',
            'payment_date.before_or_equal' => 'A payment cannot be dated in the future.',
            'payment_method_id.exists' => 'Pick a payment method that is still active.',
            'allocations.required' => 'Put the payment against at least one bill.',
            'allocations.min' => 'Put the payment against at least one bill.',
        ];
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

                if ($this->cents('amount') > 0) {
                    $method = PaymentMethod::find($this->integer('payment_method_id') ?: null);

                    if ($refusal = PaymentSettlement::methodRefusal($method, $this->input('method_reference'))) {
                        $validator->errors()->add(
                            $refusal['field'] === 'method' ? 'payment_method_id' : 'method_reference',
                            $refusal['message'],
                        );

                        return;
                    }
                }

                $bills = $this->namedBills();
                $supplierId = $this->integer('supplier_id');

                $placed = BillAllocation::weigh(
                    $this->cents('amount'),
                    $this->cents('credit_used'),
                    SupplierAccount::credit($supplierId),
                    $supplierId,
                    $this->input('allocations', []),
                    $bills,
                );

                foreach ($placed['errors'] as $field => $message) {
                    $validator->errors()->add($field, $message);
                }

                if ($placed['errors'] !== []) {
                    return;
                }

                $newest = $bills->only(array_column($placed['lines'], 'purchase_id'))->max(fn (Purchase $bill) => $bill->purchase_date);

                if ($newest && Carbon::parse($this->input('payment_date'))->lt($newest->copy()->startOfDay())) {
                    $validator->errors()->add(
                        'payment_date',
                        'A payment cannot be dated before the bill it pays. The newest one here is dated '
                            .$newest->format('d M Y').'.',
                    );
                }
            },
        ];
    }

    public function alreadySaved(): ?SupplierPayment
    {
        if (! $this->filled('idempotency_key')) {
            return null;
        }

        return SupplierPayment::firstWhere('idempotency_key', $this->input('idempotency_key'));
    }

    /** Does this form hold the very same payment that key already made? */
    public function matchesSaved(SupplierPayment $payment): bool
    {
        $same = (int) $this->input('supplier_id') === (int) $payment->supplier_id
            && (string) $this->input('payment_date') === $payment->payment_date?->toDateString()
            && $this->cents('amount') === Quantity::centavos((float) $payment->amount)
            && $this->cents('credit_used') === Quantity::centavos((float) $payment->credit_used);

        if (! $same) {
            return false;
        }

        $sent = collect($this->input('allocations', []))
            ->map(fn ($row) => [(int) ($row['purchase_id'] ?? 0), Quantity::centavos(round((float) ($row['amount'] ?? 0), 2))])
            ->reject(fn ($row) => $row[1] === 0)
            ->sortBy(fn ($row) => $row[0])
            ->values()
            ->all();

        $saved = $payment->allocations()
            ->orderBy('purchase_id')
            ->get(['purchase_id', 'amount'])
            ->map(fn ($row) => [(int) $row->purchase_id, Quantity::centavos((float) $row->amount)])
            ->all();

        return $sent === $saved;
    }

    protected function cents(string $field): int
    {
        return Quantity::centavos(round((float) $this->input($field, 0), 2));
    }

    /** The bills the form named, keyed by id. A plain read: the poster locks. */
    protected function namedBills(): Collection
    {
        $ids = collect($this->input('allocations', []))
            ->pluck('purchase_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return Purchase::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'payment_method_id' => $this->filled('payment_method_id') ? $this->input('payment_method_id') : null,
            'method_reference' => $this->filled('method_reference') ? trim((string) $this->input('method_reference')) : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
        ]);
    }
}
