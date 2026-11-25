<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A sales order that cannot take the step asked of it (phase 29).
 *
 * The form and the policy have already said no to the ordinary cases.
 * Reaching this means something changed in between — the stock was sold or
 * reserved for somebody else, or the order was approved, fulfilled or
 * cancelled a moment ago — and the answer is read again under the lock.
 */
class SalesOrderException extends RuntimeException
{
    public static function notPending(string $reference, string $standing): self
    {
        return new self("Order [{$reference}] is {$standing}, so it cannot be approved.");
    }

    public static function notOpen(string $reference, string $standing): self
    {
        return new self("Order [{$reference}] is {$standing}, so there is nothing to cancel.");
    }

    public static function notApproved(string $reference, string $standing): self
    {
        return new self("Order [{$reference}] is {$standing}. Only an approved order becomes a sale.");
    }

    public static function otherCustomer(string $reference): self
    {
        return new self("Order [{$reference}] was placed by another customer.");
    }

    public static function dateGone(): self
    {
        return new self('The stock cannot be reserved until a day that has already passed.');
    }

    /** @param  list<string>  $short */
    public static function notEnoughFree(array $short): self
    {
        return new self('Not enough free stock to reserve: '.implode('; ', $short).'.');
    }

    public static function cannotConvert(string $message): self
    {
        return new self($message);
    }
}
