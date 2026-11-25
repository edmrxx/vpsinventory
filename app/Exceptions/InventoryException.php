<?php

namespace App\Exceptions;

use App\Support\Quantity;
use RuntimeException;

/**
 * Something the ledger refused to do.
 *
 * Every message is written for the person at the screen, because that is
 * where it ends up: the controllers turn these straight into a validation
 * error or an alert. Quantities are written through `Quantity::format()`,
 * so 2.5 reads as 2.5 and 10 as 10.
 */
class InventoryException extends RuntimeException
{
    public static function nothingToPost(): self
    {
        return new self('This document has no lines, so there is nothing to post.');
    }

    public static function alreadyPosted(string $reference): self
    {
        return new self("Document [{$reference}] has already been posted.");
    }

    public static function alreadyVoided(string $reference): self
    {
        return new self("Document [{$reference}] has already been voided.");
    }

    public static function quantityMustBePositive(string $sku): self
    {
        return new self("The quantity for [{$sku}] must be more than zero.");
    }

    public static function wholeNumbersOnly(string $sku, int|float $quantity, ?string $unit): self
    {
        $quantity = Quantity::format($quantity);
        $unit = $unit ? " [{$unit}]" : '';

        return new self(
            "[{$sku}] is counted in whole numbers, so {$quantity} cannot be entered. "
                ."Turn on \"Allows decimals\" on its unit{$unit} if it is sold by measure."
        );
    }

    /**
     * A line typed in another unit that does not convert: a unit the product
     * is not sold in, or a quantity that works out to half a piece.
     */
    public static function cannotConvert(string $message): self
    {
        return new self($message);
    }

    /**
     * A sale that would take stock reserved for customer orders (phase 29),
     * or held aside for a loss report (phase 33).
     */
    public static function heldForOthers(string $sku, string $free): self
    {
        return new self(
            "Only {$free} of [{$sku}] is free to sell: the rest is reserved for customer orders or held for a loss report. "
                .'Sell from the order it is reserved for, release that order, or have the loss report answered first.'
        );
    }

    /**
     * A transfer, or its void, would take stock a customer order holds at
     * that place (phase 31.5).
     */
    public static function heldForOrdersAt(string $sku, string $location, string $free): self
    {
        return new self(
            "Only {$free} of [{$sku}] at {$location} is free to move: the rest is reserved there for customer orders or held for a loss report. "
                .'Fulfil or release those orders, or have the loss report answered first, or move less.'
        );
    }

    /** A delivery the order it names will not take (phase 23). */
    public static function orderRefused(string $message): self
    {
        return new self($message);
    }

    public static function backdated(string $date, string $last): self
    {
        return new self(
            "This document is dated {$date}, which is before the last posted document on {$last}. "
                .'Stock is costed in the order it was posted, so an earlier date cannot be accepted.'
        );
    }

    public static function notEnoughStock(string $sku, int|float $wanted, int|float $available): self
    {
        $wanted = Quantity::format($wanted);
        $available = Quantity::format($available);

        return new self(
            "There is not enough [{$sku}] in stock: {$wanted} needed, {$available} on hand."
        );
    }

    /**
     * Enough of it on the books, but not at the location the document
     * posts at (phase 31.2): the rest sits somewhere else.
     */
    public static function notEnoughStockAt(
        string $sku,
        string $location,
        int|float $wanted,
        int|float $here,
        int|float $elsewhere,
    ): self {
        $wanted = Quantity::format($wanted);
        $here = Quantity::format($here);
        $elsewhere = Quantity::format($elsewhere);

        return new self(
            "There is not enough [{$sku}] at {$location}: {$wanted} needed, {$here} there. "
                ."{$elsewhere} more is at other locations; move it here first."
        );
    }

    /** No location is marked as the default, so a document has nowhere to post. */
    public static function noDefaultLocation(): self
    {
        return new self(
            'No location is set as the default, so stock has nowhere to go. '
                .'Open Masters → Locations and make one the default.'
        );
    }

    public static function nothingToReverse(string $reference): self
    {
        return new self("Document [{$reference}] brought no stock in, so there is nothing to take back.");
    }

    public static function nothingToPutBack(string $reference): self
    {
        return new self("Document [{$reference}] took no stock out, so there is nothing to put back.");
    }

    /**
     * @param  list<array{reference:string,voidable:bool}>  $transfers  Posted
     *         transfers that moved some of it to another location (phase 31.5).
     */
    public static function stockHasMoved(string $sku, int|float $received, int|float $left, array $transfers = []): self
    {
        $gone = Quantity::format(Quantity::fromMilli(Quantity::toMilli($received) - Quantity::toMilli($left)));
        $received = Quantity::format($received);
        $left = Quantity::format($left);

        return new self(
            "Some of the [{$sku}] from this document has already gone: {$received} came in and "
                ."{$left} is left, so {$gone} has moved on. A document can only be voided while "
                .'its stock is untouched.'.self::movedByTransfer($transfers, 'bring it back into this batch')
                .' Otherwise correct it with a return or an adjustment.'
        );
    }

    /**
     * What to say about goods a transfer took out of a delivery's batch
     * (phase 31.5). A purchase return or void reads that batch only, and a
     * transfer back opens a new batch rather than refilling it, so the one
     * way back is voiding the transfer — offered only while what it brought
     * to the other place is untouched.
     *
     * @param  list<array{reference:string,voidable:bool}>  $transfers
     * @param  string  $then  What voiding the transfer lets the user do.
     */
    public static function movedByTransfer(array $transfers, string $then): string
    {
        $list = fn (array $rows) => '['.implode('], [', array_column($rows, 'reference')).']';
        $voidable = array_values(array_filter($transfers, fn ($t) => $t['voidable']));
        $stuck = array_values(array_filter($transfers, fn ($t) => ! $t['voidable']));
        $text = '';

        if ($voidable !== []) {
            $text .= ' Some of it was moved to another location by transfer '.$list($voidable)
                ."; void that transfer first to {$then}.";
        }

        if ($stuck !== []) {
            $text .= ' Some of it was moved to another location by transfer '.$list($stuck)
                .' and has since been sold or moved on there, so it can no longer be returned or voided against this delivery.';
        }

        return $text;
    }

    /** A transfer moves stock between two places, never to where it is (phase 31.5). */
    public static function sameLocation(): self
    {
        return new self('A transfer moves stock between two different locations. Pick another destination.');
    }

    /** What a transfer brought in has moved on since (phase 31.5). */
    public static function transferHasMoved(string $sku, string $location, int|float $received, int|float $left): self
    {
        $gone = Quantity::format(Quantity::fromMilli(Quantity::toMilli($received) - Quantity::toMilli($left)));

        return new self(
            "{$gone} of the [{$sku}] this transfer brought to {$location} has already been sold or moved on, "
                .'so the transfer can no longer be voided. Move what is left back with another transfer instead.'
        );
    }

    public static function moreThanWasSold(string $sku, int|float $wanted, int|float $left): self
    {
        $wanted = Quantity::format($wanted);
        $left = Quantity::format($left);

        return new self(
            "Only {$left} of [{$sku}] can still be returned from this sale, and {$wanted} was asked for."
        );
    }

    public static function notEnoughOfThisDelivery(string $sku, int|float $wanted, int|float $left): self
    {
        $wanted = Quantity::format($wanted);
        $left = Quantity::format($left);

        return new self(
            "Only {$left} of the [{$sku}] from this delivery is still on the shelf, and {$wanted} "
                .'was asked for. The rest has already been sold, so it cannot go back to the supplier.'
        );
    }

    public static function nothingReturned(): self
    {
        return new self('Nothing was entered to return.');
    }

    public static function hasPayments(string $reference): self
    {
        return new self(
            "{$reference} has a customer payment against it, so it cannot be voided. "
                .'Void the payment first, or correct the sale with a return.'
        );
    }

    /** A delivery a supplier payment still stands against (phase 24). */
    public static function hasSupplierPayments(string $reference): self
    {
        return new self(
            "{$reference} has a supplier payment against it, so it cannot be voided. "
                .'Void the payment first, or correct the delivery with a purchase return.'
        );
    }

    public static function alreadyReturned(string $reference): self
    {
        return new self(
            "Document [{$reference}] has goods returned against it, so it can no longer be voided. "
                .'Correct it with another return or an adjustment instead.'
        );
    }

    public static function layersDoNotMatchStock(string $sku, int|float $onHand, int|float $layers): self
    {
        $onHand = Quantity::format($onHand);
        $layers = Quantity::format($layers);

        return new self(
            "The stock of [{$sku}] has come apart from its batches: {$onHand} on hand "
                ."against {$layers} in the layers. The ledger stopped rather than make it worse."
        );
    }
}
