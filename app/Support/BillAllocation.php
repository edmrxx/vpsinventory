<?php

namespace App\Support;

use App\Models\Purchase;
use Illuminate\Support\Collection;

/**
 * Whether a supplier payment fits the bills it is put against (phase 24).
 *
 * The one place it is weighed — the supplier side of `PaymentAllocation`.
 * The form request asks with the bills read plainly, so each error lands
 * under its own row; `SupplierPaymentPoster` asks again with the supplier
 * and the bills locked, and that answer is the one that counts.
 *
 * The rules:
 *
 * - Every bill named must be this supplier's, posted, and still owe
 *   something; no bill takes more than it still owes.
 * - Money and supplier credit together come to more than nothing, and
 *   the credit used is no more than the supplier has.
 * - The rows add up to the money plus the credit used, exactly. Money
 *   nobody can place against a bill is refused.
 *
 * Everything is weighed in whole centavos.
 */
final class BillAllocation
{
    /**
     * @param  int  $amount  Money paid, in centavos.
     * @param  int  $creditUsed  Supplier credit used, in centavos.
     * @param  int  $creditAvailable  What the supplier has, in centavos.
     * @param  list<array{purchase_id?:mixed, amount?:mixed}>  $rows  As typed. A row of nought is left out.
     * @param  Collection<int, Purchase>  $purchases  The bills named, keyed by id.
     * @return array{lines: list<array{purchase_id:int, amount:int}>, errors: array<string, string>}
     */
    public static function weigh(int $amount, int $creditUsed, int $creditAvailable, int $supplierId, array $rows, Collection $purchases): array
    {
        $errors = [];
        $lines = [];
        $seen = [];
        $placed = 0;

        if ($amount < 0) {
            return ['lines' => [], 'errors' => ['amount' => 'The amount cannot be below nothing.']];
        }

        if ($creditUsed < 0) {
            return ['lines' => [], 'errors' => ['credit_used' => 'The credit used cannot be below nothing.']];
        }

        if ($creditUsed > $creditAvailable) {
            return ['lines' => [], 'errors' => ['credit_used' => 'This supplier has only '.self::money($creditAvailable).' of credit to use.']];
        }

        foreach (array_values($rows) as $index => $row) {
            $id = (int) ($row['purchase_id'] ?? 0);
            $cents = Quantity::centavos(round((float) ($row['amount'] ?? 0), 2));

            if ($cents === 0) {
                continue;
            }

            $bill = $purchases[$id] ?? null;

            if (! $bill || (int) $bill->supplier_id !== $supplierId || ! $bill->isPosted()) {
                $errors["allocations.{$index}.purchase_id"] = 'This bill is not one of this supplier\'s open bills.';

                continue;
            }

            if (isset($seen[$id])) {
                $errors["allocations.{$index}.purchase_id"] = "{$bill->reference} is already in the list once.";

                continue;
            }

            $seen[$id] = true;
            $owed = Quantity::centavos((float) $bill->balance_due);

            if ($cents < 0) {
                $errors["allocations.{$index}.amount"] = 'An amount cannot be below nothing.';

                continue;
            }

            if ($owed <= 0) {
                $errors["allocations.{$index}.amount"] = "{$bill->reference} is already paid.";

                continue;
            }

            if ($cents > $owed) {
                $errors["allocations.{$index}.amount"] = "{$bill->reference} owes only ".self::money($owed).'.';

                continue;
            }

            $placed += $cents;
            $lines[] = ['purchase_id' => $id, 'amount' => $cents];
        }

        if ($errors !== []) {
            return ['lines' => [], 'errors' => $errors];
        }

        $paying = $amount + $creditUsed;

        if ($paying <= 0) {
            return ['lines' => [], 'errors' => ['amount' => 'Type the amount paid, or the credit to use.']];
        }

        if ($lines === []) {
            return ['lines' => [], 'errors' => ['allocations' => 'Put the payment against at least one bill.']];
        }

        if ($placed < $paying) {
            return ['lines' => [], 'errors' => ['allocations' => self::money($paying - $placed)
                .' of the payment is not against any bill. Put it against a bill, or take the payment down to '
                .self::money($placed).'.']];
        }

        if ($placed > $paying) {
            return ['lines' => [], 'errors' => ['allocations' => 'The bills come to '.self::money($placed)
                .', more than the payment of '.self::money($paying).'.']];
        }

        return ['lines' => $lines, 'errors' => []];
    }

    private static function money(int $cents): string
    {
        return settings('system.currency_symbol', '₱').number_format($cents / 100, 2);
    }
}
