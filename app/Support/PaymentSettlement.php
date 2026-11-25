<?php

namespace App\Support;

use App\Models\PaymentMethod;
use Illuminate\Support\Collection;

/**
 * How a sale is paid: what each payment puts towards it, and the change.
 *
 * The one place this is worked out. The form request asks it so it can put
 * an error against the box that caused it; `SalePoster` asks it again
 * inside the transaction and is the one that decides. `settle()` in
 * `resources/js/lib/payments.js` draws the same sums while the cashier
 * types, in whole centavos like this.
 *
 * The rules, for a sale of ₱1,850:
 *
 * - Cash may be more than is owed. ₱2,000 handed over is ₱150 change.
 * - Nothing else may. Nobody gives change from GCash, so the other
 *   payments together may not come to more than the sale.
 * - Split: ₱1,000 GCash and ₱1,000 cash is ₱850 cash owed and ₱150 change.
 * - One cash payment per sale. Two cash rows would leave nobody sure which
 *   one the change came from.
 * - A GCash, Maya or bank payment carries its transaction number when its
 *   method says so.
 * - Without any payment the sale is not paid, and is refused — unless the
 *   list was never sent at all. A sale posted with no `payments` key is
 *   recorded as paid exactly in cash, which is what every sale was taken to
 *   be before payments were recorded. That keeps an old browser tab, open
 *   since before the update, from posting a sale nobody paid for.
 *
 * Each row is stored at what it put towards the sale, and the cash row also
 * keeps what was handed over. A sale's rows add up to its total less whatever
 * went on account (`settleSale()`). The payment rows are money that actually
 * came in, which is why Collections can be read from them.
 */
final class PaymentSettlement
{
    /** How many ways one sale may be paid. */
    public const MAX = 5;

    /**
     * Settle a sale that may be partly or wholly on the customer's account.
     *
     * Credit is always asked for in so many pesos; a payment that falls
     * short is never taken as credit. What is left after it is paid the
     * ordinary way, with one difference: no change. Cash over what is left
     * means less should go on account, not that money should cross the
     * counter on a sale nobody has finished paying for.
     *
     * Whether the customer may have that much credit is `CustomerCredit`.
     *
     * @param  int  $total  What the sale comes to, in centavos.
     * @param  int  $onAccount  How much goes on account, in centavos.
     * @param  list<array{payment_method_id?:mixed, amount?:mixed, reference?:mixed}>|null  $payments
     * @param  Collection<int, PaymentMethod>|null  $methods
     * @return array{lines: list<array{payment_method_id:int, amount:int, tendered:?int, reference:?string}>, change:int, errors: array<string, string>}
     */
    public static function settleSale(int $total, int $onAccount, ?array $payments, ?Collection $methods = null): array
    {
        if ($onAccount <= 0) {
            return self::settle($total, $payments, $methods);
        }

        $none = ['lines' => [], 'change' => 0, 'errors' => []];

        if ($onAccount > $total) {
            return [...$none, 'errors' => [
                'on_account' => 'On account cannot be more than the sale\'s '.self::money($total).'.',
            ]];
        }

        $left = $total - $onAccount;

        if ($left === 0) {
            foreach ($payments ?? [] as $payment) {
                if (Quantity::centavos(round((float) ($payment['amount'] ?? 0), 2)) > 0) {
                    return [...$none, 'errors' => [
                        'on_account' => 'The whole sale is on account, so nothing is paid now. Put less on account, or take the payment off.',
                    ]];
                }
            }

            return $none;
        }

        // A sale sent with no payments is taken as paid in cash, but that is
        // for a screen that knows nothing of credit. One that sent credit
        // has to say how the rest was paid.
        if ($payments === null) {
            return [...$none, 'errors' => ['payments' => 'Record how the rest of this sale was paid.']];
        }

        return self::settle($left, $payments, $methods, givesChange: false);
    }

    /**
     * Why this method cannot take a payment with this reference, or null.
     *
     * The one rule for a method, shared by a sale's payments and by a
     * customer paying an invoice (phase 19): it must still be switched on,
     * and one marked "Needs a reference number" takes none without it.
     *
     * @return array{field: 'method'|'reference', message: string}|null
     */
    public static function methodRefusal(?PaymentMethod $method, ?string $reference): ?array
    {
        if (! $method || ! $method->is_active) {
            return ['field' => 'method', 'message' => 'Pick a payment method that is still active.'];
        }

        if ($method->requires_reference && trim((string) $reference) === '') {
            return ['field' => 'reference', 'message' => "Type the {$method->name} reference number."];
        }

        return null;
    }

    /**
     * @param  int  $total  What the sale comes to, in centavos, from SaleTotals.
     * @param  list<array{payment_method_id?:mixed, amount?:mixed, reference?:mixed}>|null  $payments  As typed; null when none were sent.
     * @param  Collection<int, PaymentMethod>|null  $methods  Keyed by id. Loaded here when not given.
     * @param  bool  $givesChange  False on a sale partly on account.
     * @return array{lines: list<array{payment_method_id:int, amount:int, tendered:?int, reference:?string}>, change:int, errors: array<string, string>}
     */
    public static function settle(int $total, ?array $payments, ?Collection $methods = null, bool $givesChange = true): array
    {
        $none = ['lines' => [], 'change' => 0, 'errors' => []];

        // A sale that came to nothing — a full discount — took no money.
        if ($total <= 0) {
            return $none;
        }

        if ($payments === null) {
            return self::paidInCash($total);
        }

        $payments = array_values($payments);

        if ($payments === []) {
            return [...$none, 'errors' => ['payments' => 'Record how this sale was paid.']];
        }

        // Only a method still switched on can take a new payment.
        $methods ??= PaymentMethod::query()
            ->where('is_active', true)
            ->whereIn('id', array_map(fn ($p) => (int) ($p['payment_method_id'] ?? 0), $payments))
            ->get()
            ->keyBy('id');

        $errors = [];
        $cashIndex = null;
        $others = 0;
        $rows = [];

        foreach ($payments as $index => $payment) {
            $method = $methods[(int) ($payment['payment_method_id'] ?? 0)] ?? null;
            $amount = Quantity::centavos(round((float) ($payment['amount'] ?? 0), 2));
            $reference = trim((string) ($payment['reference'] ?? '')) ?: null;

            if ($refusal = self::methodRefusal($method, $reference)) {
                $errors["payments.{$index}.".($refusal['field'] === 'method' ? 'payment_method_id' : 'reference')] = $refusal['message'];

                if (! $method) {
                    continue;
                }
            }

            if ($amount <= 0) {
                $errors["payments.{$index}.amount"] = 'Type the amount paid.';
            }

            if ($method->isCash()) {
                if ($cashIndex !== null) {
                    $errors["payments.{$index}.payment_method_id"] = 'Only one cash payment per sale. Put the cash together on one line.';

                    continue;
                }

                $cashIndex = $index;
            } else {
                $others += max($amount, 0);
            }

            $rows[$index] = [
                'payment_method_id' => (int) $method->id,
                'amount' => $amount,
                'tendered' => null,
                'reference' => $reference,
                'cash' => $method->isCash(),
                'name' => $method->name,
            ];
        }

        if ($errors !== []) {
            return [...$none, 'errors' => $errors];
        }

        // Change only ever comes out of the drawer.
        if ($others > $total) {
            $names = collect($rows)->reject(fn ($row) => $row['cash'])->pluck('name');
            $verb = $names->count() === 1 ? 'comes' : 'come';

            return [...$none, 'errors' => [
                'payments' => "{$names->implode(' and ')} {$verb} to ".self::money($others).', more than the sale\'s '
                    .self::money($total).'. Change is given only from cash.',
            ]];
        }

        $change = 0;

        if ($cashIndex !== null) {
            $owed = $total - $others;
            $handed = $rows[$cashIndex]['amount'];

            if ($owed === 0) {
                return [...$none, 'errors' => [
                    "payments.{$cashIndex}.amount" => 'The other payments already cover the sale, so nothing is left to pay in cash.',
                ]];
            }

            if ($handed < $owed) {
                return [...$none, 'errors' => [
                    "payments.{$cashIndex}.amount" => self::money($owed - $handed).' more is needed: '
                        .self::money($owed).' is left to pay in cash.',
                ]];
            }

            if (! $givesChange && $handed > $owed) {
                return [...$none, 'errors' => [
                    "payments.{$cashIndex}.amount" => 'Only '.self::money($owed)
                        .' is left to pay in cash. To take more, put less on account.',
                ]];
            }

            $rows[$cashIndex]['amount'] = $owed;
            $rows[$cashIndex]['tendered'] = $handed;
            $change = $handed - $owed;
        } elseif ($others < $total) {
            return [...$none, 'errors' => [
                'payments' => 'Still '.self::money($total - $others).' to pay. The payments come to '
                    .self::money($others).' and the sale to '.self::money($total).'.',
            ]];
        }

        return [
            'lines' => array_values(array_map(fn (array $row) => [
                'payment_method_id' => $row['payment_method_id'],
                'amount' => $row['amount'],
                'tendered' => $row['tendered'],
                'reference' => $row['reference'],
            ], $rows)),
            'change' => $change,
            'errors' => [],
        ];
    }

    /**
     * The sale as paid exactly in cash: what a sale posted without any
     * payment list is taken to be.
     *
     * @return array{lines: list<array{payment_method_id:int, amount:int, tendered:?int, reference:?string}>, change:int, errors: array<string, string>}
     */
    protected static function paidInCash(int $total): array
    {
        $cash = PaymentMethod::cash();

        if (! $cash) {
            return ['lines' => [], 'change' => 0, 'errors' => [
                'payments' => 'There is no cash payment method to record this sale against.',
            ]];
        }

        return [
            'lines' => [[
                'payment_method_id' => (int) $cash->id,
                'amount' => $total,
                'tendered' => null,
                'reference' => null,
            ]],
            'change' => 0,
            'errors' => [],
        ];
    }

    protected static function money(int $cents): string
    {
        return number_format($cents / 100, 2);
    }
}
