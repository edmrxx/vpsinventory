<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A loss report step refused (phase 33). Every message is written for the
 * person at the screen, and says what to do instead.
 */
class LossReportException extends RuntimeException
{
    public static function notPending(string $reference, string $status): self
    {
        return new self("Loss report [{$reference}] is already {$status}, so it cannot be answered again.");
    }

    public static function locationOff(): self
    {
        return new self('The location of this loss has been switched off. Switch it back on first.');
    }

    public static function notEnoughFree(string $sku, string $location, string $free): self
    {
        return new self(
            "Only {$free} of [{$sku}] at {$location} is free to report as lost: the rest is reserved for customer orders or already held for another loss report."
        );
    }
}
