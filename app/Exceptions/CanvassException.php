<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A canvass step that cannot be taken (phase 24.2).
 *
 * The policy has already hidden the button; reaching this means something
 * changed in between — somebody awarded, ordered or closed the request a
 * moment ago — and the answer was read again under the row lock. `$errors`
 * carries field errors for an award, so the form can point at the item.
 */
class CanvassException extends RuntimeException
{
    /** @var array<string, string> */
    public array $errors = [];

    /** @param  array<string, string>  $errors */
    public static function invalidAward(array $errors): self
    {
        $failed = new self(reset($errors) ?: 'The award could not be saved.');
        $failed->errors = $errors;

        return $failed;
    }

    public static function notApproved(string $reference, string $status): self
    {
        return new self("Request [{$reference}] is {$status}. Suppliers are asked for prices only on an approved request.");
    }

    public static function alreadyAwarded(string $reference): self
    {
        return new self("Request [{$reference}] has already been awarded. Reopen the award first to change the quotes.");
    }

    public static function notAwarded(string $reference): self
    {
        return new self("Request [{$reference}] has no award to reopen.");
    }

    public static function alreadyOrdered(string $reference, string $order): self
    {
        return new self("Request [{$reference}] was already ordered on [{$order}], so its canvass is closed.");
    }

    public static function alreadyQuoted(string $supplier): self
    {
        return new self("{$supplier} has already quoted on this request. Remove that quote first to enter it again.");
    }

    public static function inactiveSupplier(): self
    {
        return new self('Pick a supplier that is still active.');
    }

    public static function nothingPriced(): self
    {
        return new self('Type a price for at least one item. Leave an item empty if the supplier does not carry it.');
    }

    public static function foreignLine(): self
    {
        return new self('A price was given for an item that is not on this request.');
    }
}
