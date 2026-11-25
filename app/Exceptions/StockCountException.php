<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A count sheet step refused (phase 32). Every message is written for the
 * person at the screen, and says what to do instead.
 */
class StockCountException extends RuntimeException
{
    public static function alreadyOpen(string $location, string $reference): self
    {
        return new self("Count [{$reference}] is still open at {$location}. Finish or cancel it before starting another there.");
    }

    public static function nothingToCount(string $location): self
    {
        return new self("There is nothing to count at {$location}: no active product, and nothing on hand there.");
    }

    public static function locationOff(string $location): self
    {
        return new self("{$location} has been switched off. Switch it back on to start or post a count there.");
    }

    public static function notCounting(string $reference, string $status): self
    {
        return new self("Count [{$reference}] is {$status}, so its figures can no longer be changed.");
    }

    public static function notSubmitted(string $reference, string $status): self
    {
        return new self("Count [{$reference}] is {$status}, not waiting for approval.");
    }

    public static function notOpen(string $reference, string $status): self
    {
        return new self("Count [{$reference}] is already {$status}, so there is nothing to cancel.");
    }

    public static function nothingCounted(string $reference): self
    {
        return new self("Nothing on count [{$reference}] has been counted yet. Enter at least one counted figure first.");
    }

    public static function notOnSheet(): self
    {
        return new self('A line sent is not on this count sheet. Reload the page and try again.');
    }
}
