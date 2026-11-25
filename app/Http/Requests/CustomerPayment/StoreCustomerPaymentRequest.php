<?php

namespace App\Http\Requests\CustomerPayment;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Support\PaymentAllocation;
use App\Support\PaymentSettlement;
use App\Support\Quantity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Money a customer brings in, and the invoices it pays.
 *
 * Every rule that weighs the payment against the invoices is asked of
 * `PaymentAllocation`, and the method of `PaymentSettlement`, so the error
 * lands under the row that caused it. `CustomerPaymentPoster` asks both
 * again with the rows locked, and decides.
 */
class StoreCustomerPaymentRequest extends FormRequest
{
    /** How many invoices one payment may pay. */
    public const MAX_INVOICES = 200;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // An inactive customer may still pay what they owe.
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('is_walk_in', 0)],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.Carbon::today()->toDateString()],
            'payment_method_id' => [
                'required', 'integer',
                Rule::exists('payment_methods', 'id')->where('is_active', true),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999999.99'],
            'method_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'uuid'],

            'allocations' => ['required', 'array', 'min:1', 'max:'.self::MAX_INVOICES],
            'allocations.*.sale_id' => ['required', 'integer'],
            'allocations.*.amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_id.required' => 'Pick the customer who paid.',
            'customer_id.exists' => 'Pick a customer with an account. A walk-in sale is paid at the counter.',
            'payment_date.before_or_equal' => 'A payment cannot be dated in the future.',
            'payment_method_id.required' => 'Pick how the customer paid.',
            'payment_method_id.exists' => 'Pick a payment method that is still active.',
            'amount.required' => 'Type the amount the customer paid.',
            'amount.min' => 'Type the amount the customer paid.',
            'allocations.required' => 'Put the payment against at least one invoice.',
            'allocations.min' => 'Put the payment against at least one invoice.',
        ];
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

                // This form already recorded a payment, so the invoices it
                // paid owe less now — because of that very payment. Weighing
                // it again would refuse a payment that went through. The
                // controller decides what to do with a repeat.
                if ($this->alreadySaved()) {
                    return;
                }

                $method = PaymentMethod::find($this->integer('payment_method_id'));

                if ($refusal = PaymentSettlement::methodRefusal($method, $this->input('method_reference'))) {
                    $validator->errors()->add(
                        $refusal['field'] === 'method' ? 'payment_method_id' : 'method_reference',
                        $refusal['message'],
                    );

                    return;
                }

                $sales = $this->namedSales();

                $placed = PaymentAllocation::weigh(
                    $this->amountCents(),
                    $this->integer('customer_id'),
                    $this->input('allocations', []),
                    $sales,
                );

                foreach ($placed['errors'] as $field => $message) {
                    $validator->errors()->add($field, $message);
                }

                if ($placed['errors'] !== []) {
                    return;
                }

                $newest = $sales->only(array_column($placed['lines'], 'sale_id'))->max(fn (Sale $sale) => $sale->sale_date);

                if ($newest && Carbon::parse($this->input('payment_date'))->lt($newest->copy()->startOfDay())) {
                    $validator->errors()->add(
                        'payment_date',
                        'A payment cannot be dated before the invoice it pays. The newest one here is dated '
                            .$newest->format('d M Y').'.',
                    );
                }
            },
        ];
    }

    public function alreadySaved(): ?CustomerPayment
    {
        if (! $this->filled('idempotency_key')) {
            return null;
        }

        return CustomerPayment::firstWhere('idempotency_key', $this->input('idempotency_key'));
    }

    /** Does this form hold the very same payment that key already made? */
    public function matchesSaved(CustomerPayment $payment): bool
    {
        $same = (int) $this->input('customer_id') === (int) $payment->customer_id
            && (string) $this->input('payment_date') === $payment->payment_date?->toDateString()
            && (int) $this->input('payment_method_id') === (int) $payment->payment_method_id
            && $this->amountCents() === Quantity::centavos((float) $payment->amount)
            && (trim((string) $this->input('method_reference')) ?: null) === $payment->method_reference;

        if (! $same) {
            return false;
        }

        $sent = collect($this->input('allocations', []))
            ->map(fn ($row) => [(int) ($row['sale_id'] ?? 0), Quantity::centavos(round((float) ($row['amount'] ?? 0), 2))])
            ->reject(fn ($row) => $row[1] === 0)
            ->sortBy(fn ($row) => $row[0])
            ->values()
            ->all();

        $saved = $payment->allocations()
            ->orderBy('sale_id')
            ->get(['sale_id', 'amount'])
            ->map(fn ($row) => [(int) $row->sale_id, Quantity::centavos((float) $row->amount)])
            ->all();

        return $sent === $saved;
    }

    protected function amountCents(): int
    {
        return Quantity::centavos(round((float) $this->input('amount'), 2));
    }

    /** The sales the form named, keyed by id. A plain read: the poster locks. */
    protected function namedSales(): \Illuminate\Support\Collection
    {
        $ids = collect($this->input('allocations', []))
            ->pluck('sale_id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return Sale::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'method_reference' => $this->filled('method_reference') ? trim((string) $this->input('method_reference')) : null,
            'notes' => $this->filled('notes') ? trim((string) $this->input('notes')) : null,
        ]);
    }
}
