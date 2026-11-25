<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A purchase order that cannot be placed or cancelled as asked.
 *
 * The form and the policy have already said no to the ordinary cases.
 * Reaching this means something changed in between — the request was
 * ordered or closed by somebody else a moment ago — and the answer is read
 * again under the row lock.
 */
class PurchaseOrderException extends RuntimeException
{
    public static function requestNotApproved(string $reference, string $status): self
    {
        return new self("Request [{$reference}] is {$status}, so it cannot be ordered. Only an approved request becomes an order.");
    }

    /** Phase 24.2: an awarded request is ordered from its award (phase 24.3), not by hand. */
    public static function requestAwarded(string $reference): self
    {
        return new self("Request [{$reference}] has been awarded to its suppliers, so its orders are made from the award. Reopen the award to order it by hand.");
    }

    public static function requestNotAwarded(string $reference): self
    {
        return new self("Request [{$reference}] has no award, so there is nothing to order from it. Choose the suppliers first, or order it by hand.");
    }

    public static function requestAlreadyOrdered(string $request, string $order): self
    {
        return new self("Request [{$request}] was already ordered on [{$order}].");
    }

    public static function notOpen(string $reference, string $status): self
    {
        return new self("Order [{$reference}] is {$status}, so it cannot be cancelled. Goods received against an order are closed short instead.");
    }

    public static function notPartlyReceived(string $reference, string $status): self
    {
        return new self("Order [{$reference}] is {$status}. Only a partly received order is closed short; one with nothing received is cancelled.");
    }

    public static function notPendingApproval(string $reference, string $status): self
    {
        return new self("Order [{$reference}] is {$status}, so it is no longer waiting for approval.");
    }

    public static function ownOrder(string $reference): self
    {
        return new self("You placed order [{$reference}] yourself, so somebody else must approve or reject it.");
    }

    public static function cannotConvert(string $message): self
    {
        return new self($message);
    }
}
