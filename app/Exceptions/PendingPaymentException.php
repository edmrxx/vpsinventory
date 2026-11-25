<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A pending payment that cannot take the step asked of it (CR-2).
 *
 * The form has already said no to the ordinary cases. Reaching this means
 * something changed in between — the goods were taken, or the order was
 * paid, cancelled or expired a moment ago on another screen — and the
 * answer is read again under the lock.
 */
class PendingPaymentException extends RuntimeException
{
    public static function notWaiting(string $reference, string $standing): self
    {
        return new self("Pending payment [{$reference}] is ".strtolower($standing).', so it cannot be paid.');
    }

    public static function notOpen(string $reference, string $standing): self
    {
        return new self("Pending payment [{$reference}] is ".strtolower($standing).', so there is nothing to cancel.');
    }

    public static function otherCustomer(string $reference): self
    {
        return new self("Pending payment [{$reference}] was sent for another customer.");
    }

    public static function otherPlace(string $reference, string $place): self
    {
        return new self("Pending payment [{$reference}] is paid at {$place}, where its goods are held.");
    }

    public static function linesChanged(string $reference): self
    {
        return new self("The items or prices differ from pending payment [{$reference}]. It is paid exactly as it was sent; cancel it to change anything.");
    }

    /**
     * @param  list<string>  $short
     */
    public static function notEnoughFree(array $short): self
    {
        return new self('Not enough is free to hold for this order: '.implode('; ', $short).'.');
    }
}
