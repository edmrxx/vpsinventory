<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A quotation that cannot take the step asked of it.
 *
 * The form and the policy have already said no to the ordinary cases.
 * Reaching this means something changed in between — somebody else turned
 * it into a sale or cancelled it a moment ago — and the answer is read again
 * under the row lock.
 */
class QuotationException extends RuntimeException
{
    public static function notLive(string $reference, string $standing): self
    {
        return new self("Quotation [{$reference}] is {$standing}, so it cannot become a sale. Quote it again instead.");
    }

    public static function notOpen(string $reference, string $standing): self
    {
        return new self("Quotation [{$reference}] is {$standing}, so there is nothing to cancel.");
    }

    public static function otherCustomer(string $reference): self
    {
        return new self("Quotation [{$reference}] was made for another customer.");
    }

    public static function cannotConvert(string $message): self
    {
        return new self($message);
    }
}
