<?php

namespace App\Support;

use App\Models\PaymentMethod;

/**
 * How a delivery is paid (phase 24.5, CR-5). The only place it is weighed:
 * the form request asks it for the errors, `PurchasePoster` asks again inside
 * the transaction and decides — the same pairing as `PaymentSettlement` for a
 * sale.
 *
 * Three ways a delivery is paid:
 *
 * - **paid in full on delivery** — `on_account` off (the rule since phase 24);
 * - **all on the supplier's account** — `on_account` on, nothing paid now;
 * - **a down payment** — `on_account` on and `paid_now` more than nothing:
 *   that much paid today, the rest on account.
 *
 * Whatever is paid today names **how**: a payment method still switched on,
 * with its reference when the method asks for one (`methodRefusal()`, the
 * sale's own rule). **A form with no `payment_method_id` key at all is paid
 * in cash** — a page opened before this phase, as a sale with no `payments`
 * key is. Nothing paid today names no method.
 */
final class DeliveryPayment
{
    /**
     * @param  int  $total  What the delivery comes to, in centavos.
     * @param  bool  $methodSent  Whether the form carried a `payment_method_id` key at all.
     * @return array{paid: int, on_account: int, payment_method_id: ?int, payment_reference: ?string, errors: array<string, string>}
     */
    public static function weigh(
        int $total,
        bool $onAccount,
        int|float|string|null $paidNow,
        bool $methodSent,
        int|string|null $methodId,
        ?string $reference,
    ): array {
        $errors = [];
        $paid = $onAccount ? Quantity::centavos(round((float) ($paidNow ?? 0), 2)) : $total;

        if ($paid < 0) {
            $errors['paid_now'] = 'A payment cannot be less than nothing.';
            $paid = 0;
        } elseif ($paid > $total) {
            $errors['paid_now'] = 'Paid now cannot be more than the delivery comes to ('.number_format($total / 100, 2).').';
            $paid = $total;
        }

        $method = null;
        $reference = trim((string) $reference) === '' ? null : trim((string) $reference);

        if ($paid > 0) {
            $method = $methodSent
                ? ($methodId ? PaymentMethod::find((int) $methodId) : null)
                : PaymentMethod::cash();

            if ($methodSent && ! $methodId) {
                $errors['payment_method_id'] = 'Pick how this delivery was paid.';
            } elseif ($refusal = PaymentSettlement::methodRefusal($method, $reference)) {
                $errors[$refusal['field'] === 'method' ? 'payment_method_id' : 'payment_reference'] = $refusal['message'];
            }
        }

        return [
            'paid' => $paid,
            'on_account' => $total - $paid,
            'payment_method_id' => $paid > 0 ? $method?->id : null,
            'payment_reference' => $paid > 0 ? $reference : null,
            'errors' => $errors,
        ];
    }
}
