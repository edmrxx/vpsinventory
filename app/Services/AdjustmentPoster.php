<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\DocumentStatus;
use App\Enums\MovementType;
use App\Exceptions\InventoryException;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAdjustmentLine;
use App\Models\InventoryMovement;
use App\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes an adjustment and posts it in the same breath.
 *
 * This is how opening stock arrives. A product cannot simply be given a
 * number: the figure has to come from a document, so the batches behind it
 * exist and a later sale has something real to cost against.
 *
 * Phase 10 puts a screen in front of this. Nothing here changes when it does.
 */
class AdjustmentPoster
{
    public function __construct(
        protected InventoryLedger $ledger,
        protected DocumentNumber $numbers,
    ) {}

    /**
     * The stock a business already had on the day it started using the app.
     *
     * @param  list<array{product_id:int,quantity:int|float|string,unit_cost?:float|string|null}>  $lines
     */
    public function openingBalance(array $lines, ?Carbon $date = null, ?string $notes = null): InventoryAdjustment
    {
        return $this->post(InventoryAdjustment::KIND_OPENING, $lines, $date, 'Opening stock', $notes);
    }

    /**
     * Post an adjustment of any kind.
     *
     * @param  string  $kind  opening | increase | decrease
     * @param  list<array{product_id:int,quantity:int|float|string,unit_cost?:float|string|null}>  $lines
     * @param  int|null  $locationId  Where the stock is counted in or out
     *                                (phase 31.4); null for the default.
     */
    public function post(
        string $kind,
        array $lines,
        ?Carbon $date = null,
        ?string $reason = null,
        ?string $notes = null,
        ?string $idempotencyKey = null,
        ?int $locationId = null,
    ): InventoryAdjustment {
        $date ??= Carbon::today();
        $type = $this->movementType($kind);

        // Retried, because the very first document of a year has to create
        // its counter row. Two of them at once take gap locks on the same
        // empty range and MySQL kills one with a deadlock rather than a
        // duplicate-key error. The retry has to sit on the outermost
        // transaction: a nested one only rethrows.
        return DB::transaction(function () use ($kind, $lines, $date, $reason, $notes, $type, $idempotencyKey, $locationId) {
            // Where the stock is counted (phase 31.4), read with a shared
            // lock before the number, as a delivery reads where it lands.
            $location = $this->location($locationId);

            // Taken inside the transaction, so a document that fails to post
            // does not burn a reference.
            $reference = $this->numbers->next('documents.adjustment_prefix', $date);

            $adjustment = new InventoryAdjustment([
                'reference' => $reference,
                'location_id' => $location->id,
                'kind' => $kind,
                'status' => DocumentStatus::Posted,
                'adjustment_date' => $date,
                'reason' => $reason,
                'notes' => $notes,
                'total_cost' => 0,
                'idempotency_key' => $idempotencyKey,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            // The document writes one Post row of its own below, so the
            // observer's Create row would only say the same thing twice.
            app(AuditService::class)->withoutAuditing(fn () => $adjustment->save());

            foreach ($lines as $line) {
                InventoryAdjustmentLine::create([
                    'inventory_adjustment_id' => $adjustment->id,
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'] ?? 0,
                    'total_cost' => 0,
                ]);
            }

            $movements = $this->ledger->post($adjustment, $reference, $date, $type, $lines, $location);

            $total = $this->copyCostsBack($adjustment, $movements);

            $adjustment->forceFill(['total_cost' => $total])->saveQuietly();

            $adjustment->auditCustom(
                AuditAction::Post,
                "Posted adjustment [{$reference}]: ".count($lines).' line(s), '
                    .$type->label().'.',
                ['kind' => $kind, 'lines' => count($lines), 'total_cost' => $total],
            );

            return $adjustment->fresh(['lines']);
        }, attempts: 3);
    }

    /**
     * Where an adjustment counts its stock: the one named, or the default.
     * A location switched off since the form was checked is refused.
     */
    protected function location(?int $locationId): Location
    {
        if ($locationId === null) {
            return Location::default() ?? throw InventoryException::noDefaultLocation();
        }

        $location = Location::whereKey($locationId)->sharedLock()->first();

        if (! $location || ! $location->is_active) {
            throw new InventoryException('The location picked for this adjustment has been switched off. Pick another one.');
        }

        return $location;
    }

    /**
     * Undo a posted adjustment.
     *
     * Which way round depends on which way the stock went. One that brought
     * stock in is taken back off, and only while that stock is untouched —
     * the ledger refuses otherwise. One that took stock out puts it back
     * into the batches it came from.
     */
    public function void(InventoryAdjustment $adjustment, string $reason): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $reason) {
            $locked = InventoryAdjustment::whereKey($adjustment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== DocumentStatus::Posted) {
                throw InventoryException::alreadyVoided($locked->reference);
            }

            $wentIn = $this->movementType($locked->kind)->isIncoming();

            $wentIn
                ? $this->ledger->reverseReceipt($locked, $locked->reference, Carbon::today())
                : $this->ledger->reverseIssue($locked, $locked->reference, Carbon::today());

            app(AuditService::class)->withoutAuditing(fn () => $locked->forceFill([
                'status' => DocumentStatus::Voided,
                'voided_at' => now(),
                'voided_by' => auth()->id(),
            ])->save());

            $locked->auditCustom(
                AuditAction::Void,
                "Voided adjustment [{$locked->reference}]: {$reason}",
                ['reason' => $reason],
            );

            return $locked->fresh(['lines']);
        }, attempts: 3);
    }

    /**
     * An outgoing line is costed by the batches it ate, so the figure is only
     * known once the ledger has run. Copy it back onto the line.
     *
     * @param  list<InventoryMovement>  $movements
     */
    protected function copyCostsBack(InventoryAdjustment $adjustment, array $movements): float
    {
        $total = 0.0;

        $lines = $adjustment->lines()->orderBy('id')->get();

        foreach ($movements as $index => $movement) {
            $line = $lines[$index] ?? null;

            if (! $line) {
                continue;
            }

            $line->forceFill([
                'unit_cost' => $movement->unit_cost,
                'total_cost' => $movement->total_cost,
            ])->save();

            $total += (float) $movement->total_cost;
        }

        return round($total, 2);
    }

    protected function movementType(string $kind): MovementType
    {
        return match ($kind) {
            InventoryAdjustment::KIND_OPENING => MovementType::Opening,
            InventoryAdjustment::KIND_INCREASE => MovementType::AdjustmentIn,
            InventoryAdjustment::KIND_DECREASE => MovementType::AdjustmentOut,
            default => throw new \InvalidArgumentException("Unknown adjustment kind [{$kind}]."),
        };
    }
}
