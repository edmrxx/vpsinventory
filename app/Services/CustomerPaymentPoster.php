<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\DocumentStatus;
use App\Exceptions\InventoryException;
use App\Exceptions\PaymentException;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Support\PaymentAllocation;
use App\Support\PaymentSettlement;
use App\Support\Quantity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Records money a customer brings in against what they owe, and takes it off
 * the invoices it pays.
 *
 * The same shape as `SalePoster`: everything inside one transaction on
 * `attempts: 3`, every figure weighed again here, whatever the form sent.
 * No stock moves, so the ledger is not called.
 *
 * It takes its locks in this order, and so must anything added later that
 * touches the same rows: the customer, then the sales it pays in ascending
 * id order, then the document number. A sale posted on account locks the
 * customer first too, so the two wait for each other rather than both
 * reading the same balance. A void locks the payment row first, then its
 * sales in ascending id order.
 *
 * It writes `balance_due` outside the ledger, so it flushes the dashboard's
 * figures itself, once the transaction has committed.
 */
class CustomerPaymentPoster
{
    public function __construct(
        protected DocumentNumber $numbers,
        protected AuditService $audit,
    ) {}

    /**
     * @param  array{customer_id:int, payment_date:string|Carbon, payment_method_id:int, amount:float|string, method_reference?:string|null, notes?:string|null, idempotency_key?:string|null}  $header
     * @param  list<array{sale_id:int, amount:float|string}>  $allocations
     */
    public function post(array $header, array $allocations): CustomerPayment
    {
        $date = $header['payment_date'] instanceof Carbon
            ? $header['payment_date']->copy()->startOfDay()
            : Carbon::parse($header['payment_date'])->startOfDay();

        $amount = Quantity::centavos(round((float) $header['amount'], 2));
        $reference = trim((string) ($header['method_reference'] ?? '')) ?: null;

        return DB::transaction(function () use ($header, $allocations, $date, $amount, $reference) {
            $customer = Customer::whereKey($header['customer_id'])->lockForUpdate()->firstOrFail();

            $sales = $this->lockedSales(array_map(fn ($row) => (int) ($row['sale_id'] ?? 0), $allocations));

            $placed = PaymentAllocation::weigh($amount, (int) $customer->id, $allocations, $sales);

            if ($placed['errors'] !== []) {
                throw PaymentException::unsettled(reset($placed['errors']));
            }

            $method = PaymentMethod::find($header['payment_method_id']);

            if ($refusal = PaymentSettlement::methodRefusal($method, $reference)) {
                throw PaymentException::unsettled($refusal['message']);
            }

            if ($date->isAfter(Carbon::today())) {
                throw PaymentException::unsettled('A payment cannot be dated in the future.');
            }

            $newest = $sales->only(array_column($placed['lines'], 'sale_id'))->max(fn (Sale $sale) => $sale->sale_date);

            if ($newest && $date->lt($newest->copy()->startOfDay())) {
                throw PaymentException::unsettled(
                    'A payment cannot be dated before the invoice it pays. The newest one here is dated '
                        .$newest->format('d M Y').'.'
                );
            }

            $number = $this->numbers->next('documents.customer_payment_prefix', $date);

            $payment = new CustomerPayment([
                'reference' => $number,
                'customer_id' => $customer->id,
                'payment_date' => $date,
                'status' => DocumentStatus::Posted,
                'payment_method_id' => $method->id,
                'amount' => $amount / 100,
                'method_reference' => $reference,
                'notes' => $header['notes'] ?? null,
                'idempotency_key' => $header['idempotency_key'] ?? null,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            $this->audit->withoutAuditing(fn () => $payment->save());

            foreach ($placed['lines'] as $line) {
                CustomerPaymentAllocation::create([
                    'customer_payment_id' => $payment->id,
                    'sale_id' => $line['sale_id'],
                    'amount' => $line['amount'] / 100,
                ]);

                // The sale is locked, so the exact new figure is written
                // rather than a decrement SQLite would do as a float.
                $sale = $sales[$line['sale_id']];
                $sale->forceFill([
                    'balance_due' => (Quantity::centavos((float) $sale->balance_due) - $line['amount']) / 100,
                ])->saveQuietly();
            }

            $payment->auditCustom(
                AuditAction::Post,
                "Posted customer payment [{$number}] from {$customer->name}: "
                    .number_format($amount / 100, 2).' by '.$method->name.'.',
                [
                    'amount' => $amount / 100,
                    'method' => $method->name,
                    'method_reference' => $reference,
                    'invoices' => collect($placed['lines'])
                        ->map(fn (array $line) => $sales[$line['sale_id']]->reference.' '.number_format($line['amount'] / 100, 2))
                        ->implode(', '),
                ],
            );

            $this->refreshTheFigures();

            return $payment->fresh(['allocations.sale', 'customer', 'method']);
        }, attempts: 3);
    }

    /**
     * Take a payment back: every invoice it paid owes that much again.
     *
     * Dated today, like every void, and it has to say why. The sales it paid
     * are still posted — a sale with a payment against it cannot be voided —
     * so each simply owes again what this took off it.
     */
    public function void(CustomerPayment $payment, string $reason): CustomerPayment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $locked = CustomerPayment::whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPosted()) {
                throw InventoryException::alreadyVoided($locked->reference);
            }

            $allocations = $locked->allocations()->get();
            $sales = $this->lockedSales($allocations->pluck('sale_id')->all());

            foreach ($allocations as $allocation) {
                $sale = $sales[$allocation->sale_id];

                $sale->forceFill([
                    'balance_due' => (Quantity::centavos((float) $sale->balance_due)
                        + Quantity::centavos((float) $allocation->amount)) / 100,
                ])->saveQuietly();
            }

            $this->audit->withoutAuditing(fn () => $locked->forceFill([
                'status' => DocumentStatus::Voided,
                'voided_at' => now(),
                'voided_by' => auth()->id(),
                'void_reason' => $reason,
            ])->save());

            $locked->auditCustom(
                AuditAction::Void,
                "Voided customer payment [{$locked->reference}]: {$reason}",
                ['amount' => (float) $locked->amount],
            );

            $this->refreshTheFigures();

            return $locked->fresh(['allocations.sale', 'customer', 'method']);
        }, attempts: 3);
    }

    /**
     * The sales named, held still in ascending id order, keyed by id.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Sale>
     */
    protected function lockedSales(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);

        if ($ids === []) {
            return collect();
        }

        return Sale::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * The dashboard reads balances and money in, so its cached figures go
     * once the payment is safely committed. Inside the transaction another
     * request could cache the state from before it.
     */
    protected function refreshTheFigures(): void
    {
        DB::afterCommit(fn () => app(InventoryMetrics::class)->flush());
    }
}
