<?php

namespace App\Support;

use App\Models\Sale;
use Illuminate\Support\Collection;

/**
 * Whether a customer payment fits the invoices it is put against.
 *
 * The one place it is weighed. The form request asks with the sales read
 * plainly, so each error lands under its own row; `CustomerPaymentPoster`
 * asks again with the customer and the sales locked, and that answer is the
 * one that counts: a till may have taken a return off one of those invoices
 * in between.
 *
 * The rules:
 *
 * - Every invoice named must be this customer's, posted, and still owe
 *   something.
 * - No invoice may take more than it still owes.
 * - The rows add up to the payment exactly. Money nobody can place against
 *   an invoice would be customer credit, which is stage 6, so it is refused
 *   rather than kept somewhere nobody can see it.
 *
 * Everything is weighed in whole centavos.
 */
final class PaymentAllocation
{
    /**
     * @param  int  $amount  The payment, in centavos.
     * @param  list<array{sale_id?:mixed, amount?:mixed}>  $rows  As typed. A row of nought is left out.
     * @param  Collection<int, Sale>  $sales  The sales named, keyed by id.
     * @return array{lines: list<array{sale_id:int, amount:int}>, errors: array<string, string>}
     */
    public static function weigh(int $amount, int $customerId, array $rows, Collection $sales): array
    {
        $errors = [];
        $lines = [];
        $seen = [];
        $placed = 0;

        foreach (array_values($rows) as $index => $row) {
            $id = (int) ($row['sale_id'] ?? 0);
            $cents = Quantity::centavos(round((float) ($row['amount'] ?? 0), 2));

            if ($cents === 0) {
                continue;
            }

            $sale = $sales[$id] ?? null;

            if (! $sale || (int) $sale->customer_id !== $customerId || ! $sale->isPosted()) {
                $errors["allocations.{$index}.sale_id"] = 'This invoice is not one of this customer\'s open invoices.';

                continue;
            }

            if (isset($seen[$id])) {
                $errors["allocations.{$index}.sale_id"] = "{$sale->reference} is already in the list once.";

                continue;
            }

            $seen[$id] = true;
            $owed = Quantity::centavos((float) $sale->balance_due);

            if ($cents < 0) {
                $errors["allocations.{$index}.amount"] = 'An amount cannot be below nothing.';

                continue;
            }

            if ($owed <= 0) {
                $errors["allocations.{$index}.amount"] = "{$sale->reference} is already paid.";

                continue;
            }

            if ($cents > $owed) {
                $errors["allocations.{$index}.amount"] = "{$sale->reference} owes only ".self::money($owed).'.';

                continue;
            }

            $placed += $cents;
            $lines[] = ['sale_id' => $id, 'amount' => $cents];
        }

        if ($errors !== []) {
            return ['lines' => [], 'errors' => $errors];
        }

        if ($amount <= 0) {
            return ['lines' => [], 'errors' => ['amount' => 'Type the amount the customer paid.']];
        }

        if ($lines === []) {
            return ['lines' => [], 'errors' => ['allocations' => 'Put the payment against at least one invoice.']];
        }

        if ($placed < $amount) {
            return ['lines' => [], 'errors' => ['allocations' => self::money($amount - $placed)
                .' of the payment is not against any invoice. Put it against an invoice, or take the payment down to '
                .self::money($placed).'.']];
        }

        if ($placed > $amount) {
            return ['lines' => [], 'errors' => ['allocations' => 'The invoices come to '.self::money($placed)
                .', more than the payment of '.self::money($amount).'.']];
        }

        return ['lines' => $lines, 'errors' => []];
    }

    private static function money(int $cents): string
    {
        return settings('system.currency_symbol', '₱').number_format($cents / 100, 2);
    }
}
