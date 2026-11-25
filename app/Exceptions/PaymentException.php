<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A sale whose payments do not settle it.
 *
 * The form request has already said which box is wrong. Reaching this
 * means something changed in between — a payment method switched off — and
 * the sale must not post on payments nobody checked. The controller turns
 * it into an error under the payment section.
 */
class PaymentException extends RuntimeException
{
    public static function unsettled(string $message): self
    {
        return new self($message);
    }
}
