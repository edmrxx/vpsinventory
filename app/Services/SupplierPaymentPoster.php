<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\DocumentStatus;
use App\Exceptions\InventoryException;
use App\Exceptions\PaymentException;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Support\BillAllocation;
use App\Support\PaymentSettlement;
use App\Support\Quantity;
use App\Support\SupplierAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Records money paid to a supplier against its bills, and takes it off them
 * (phase 24).
 *
 * The supplier side of `CustomerPaymentPoster`: one transaction on
 * `attempts: 3`, every figure weighed again here whatever the form sent. No
 * stock moves, so the ledger is not called.
 *
 * Locks, in this order: the supplier, then the bills it pays in ascending id
 * order, then the document number. A void locks the payment row, then its
 * bills in ascending id order. A purchase's void reads the payments against
 * it with a locking read.
 *
 * It writes `balance_due` outside the ledger, so it flushes the dashboard's
 * figures itself, once the transaction has committed.
 */
class SupplierPaymentPoster
{
    public function __construct(
        protected DocumentNumber $numbers,
        protected AuditService $audit,
    ) {}

    /**
     * @param  array{supplier_id:int, payment_date:string|Carbon, payment_method_id?:int|null, amount?:float|string|null, credit_used?:float|string|null, method_reference?:string|null, notes?:string|null, idempotency_key?:string|null}  $header
     * @param  list<array{purchase_id:int, amount:float|string}>  $allocations
     */
    public function post(array $header, array $allocations): SupplierPayment
    {
        $date = $header['payment_date'] instanceof Carbon
            ? $header['payment_date']->copy()->startOfDay()
            : Carbon::parse($header['payment_date'])->startOfDay();

        $amount = Quantity::centavos(round((float) ($header['amount'] ?? 0), 2));
        $creditUsed = Quantity::centavos(round((float) ($header['credit_used'] ?? 0), 2));
        $reference = trim((string) ($header['method_reference'] ?? '')) ?: null;

        return DB::transaction(function () use ($header, $allocations, $date, $amount, $creditUsed, $reference) {
            $supplier = Supplier::whereKey($header['supplier_id'])->lockForUpdate()->firstOrFail();

            $bills = $this->lockedBills(array_map(fn ($row) => (int) ($row['purchase_id'] ?? 0), $allocations));

            $placed = BillAllocation::weigh(
                $amount,
                $creditUsed,
                SupplierAccount::credit($supplier->id, locking: true),
                (int) $supplier->id,
                $allocations,
                $bills,
            );

            if ($placed['errors'] !== []) {
                throw PaymentException::unsettled(reset($placed['errors']));
            }

            // Money that went out needs a method; credit alone does not.
            $method = null;

            if ($amount > 0) {
                $method = PaymentMethod::find($header['payment_method_id'] ?? null);

                if ($refusal = PaymentSettlement::methodRefusal($method, $reference)) {
                    throw PaymentException::unsettled($refusal['message']);
                }
            }

            if ($date->isAfter(Carbon::today())) {
                throw PaymentException::unsettled('A payment cannot be dated in the future.');
            }

            $newest = $bills->only(array_column($placed['lines'], 'purchase_id'))->max(fn (Purchase $bill) => $bill->purchase_date);

            if ($newest && $date->lt($newest->copy()->startOfDay())) {
                throw PaymentException::unsettled(
                    'A payment cannot be dated before the bill it pays. The newest one here is dated '
                        .$newest->format('d M Y').'.'
                );
            }

            $number = $this->numbers->next('documents.supplier_payment_prefix', $date);

            $payment = new SupplierPayment([
                'reference' => $number,
                'supplier_id' => $supplier->id,
                'payment_date' => $date,
                'status' => DocumentStatus::Posted,
                'payment_method_id' => $method?->id,
                'amount' => $amount / 100,
                'credit_used' => $creditUsed / 100,
                'method_reference' => $method ? $reference : null,
                'notes' => $header['notes'] ?? null,
                'idempotency_key' => $header['idempotency_key'] ?? null,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            $this->audit->withoutAuditing(fn () => $payment->save());

            foreach ($placed['lines'] as $line) {
                SupplierPaymentAllocation::create([
                    'supplier_payment_id' => $payment->id,
                    'purchase_id' => $line['purchase_id'],
                    'amount' => $line['amount'] / 100,
                ]);

                // The bill is locked, so the exact new figure is written.
                $bill = $bills[$line['purchase_id']];
                $bill->forceFill([
                    'balance_due' => (Quantity::centavos((float) $bill->balance_due) - $line['amount']) / 100,
                ])->saveQuietly();
            }

            $how = collect([
                $amount > 0 ? number_format($amount / 100, 2).' by '.$method->name : null,
                $creditUsed > 0 ? number_format($creditUsed / 100, 2).' from supplier credit' : null,
            ])->filter()->implode(' and ');

            $payment->auditCustom(
                AuditAction::Post,
                "Posted supplier payment [{$number}] to {$supplier->name}: {$how}.",
                [
                    'amount' => $amount / 100,
                    'credit_used' => $creditUsed / 100,
                    'method' => $method?->name,
                    'method_reference' => $method ? $reference : null,
                    'bills' => collect($placed['lines'])
                        ->map(fn (array $line) => $bills[$line['purchase_id']]->reference.' '.number_format($line['amount'] / 100, 2))
                        ->implode(', '),
                ],
            );

            $this->refreshTheFigures();

            return $payment->fresh(['allocations.purchase', 'supplier', 'method']);
        }, attempts: 3);
    }

    /**
     * Take a payment back: every bill it paid is owed that much again, and
     * any supplier credit it used is the supplier's to use again.
     */
    public function void(SupplierPayment $payment, string $reason): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $locked = SupplierPayment::whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPosted()) {
                throw InventoryException::alreadyVoided($locked->reference);
            }

            $allocations = $locked->allocations()->get();
            $bills = $this->lockedBills($allocations->pluck('purchase_id')->all());

            foreach ($allocations as $allocation) {
                $bill = $bills[$allocation->purchase_id];

                $bill->forceFill([
                    'balance_due' => (Quantity::centavos((float) $bill->balance_due)
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
                "Voided supplier payment [{$locked->reference}]: {$reason}",
                ['amount' => (float) $locked->amount, 'credit_used' => (float) $locked->credit_used],
            );

            $this->refreshTheFigures();

            return $locked->fresh(['allocations.purchase', 'supplier', 'method']);
        }, attempts: 3);
    }

    /**
     * The bills named, held still in ascending id order, keyed by id.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Purchase>
     */
    protected function lockedBills(array $ids): Collection
    {
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);

        if ($ids === []) {
            return collect();
        }

        return Purchase::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /** The dashboard reads what is owed and money out; flush after commit. */
    protected function refreshTheFigures(): void
    {
        DB::afterCommit(fn () => app(InventoryMetrics::class)->flush());
    }
}
