<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A purchase request that cannot take the step asked of it.
 *
 * The policy has already hidden the button. Reaching this means something
 * changed in between — somebody else approved or closed it a moment ago —
 * and the answer is read again under the row lock. The controller turns it
 * into an error the screen can show.
 */
class PurchaseRequestException extends RuntimeException
{
    public static function notPending(string $reference, string $status): self
    {
        return new self("Request [{$reference}] is already {$status}, so it cannot be decided again.");
    }

    public static function nothingAwarded(string $reference): self
    {
        return new self("Request [{$reference}] has no item awarded to a supplier.");
    }

    public static function notOpen(string $reference, string $status): self
    {
        return new self("Request [{$reference}] is already {$status}, so there is nothing to close.");
    }

    public static function ownRequest(string $reference): self
    {
        return new self("You raised request [{$reference}], so somebody else must approve or reject it.");
    }

    public static function cannotConvert(string $message): self
    {
        return new self($message);
    }
}
