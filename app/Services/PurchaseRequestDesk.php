<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\PurchaseRequestStatus;
use App\Exceptions\PurchaseRequestException;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestLine;
use App\Models\User;
use App\Support\Quantity;
use App\Support\UnitConversion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Raises a purchase request, and records the answer to one (phase 21).
 *
 * Not a poster: nothing is posted, no stock moves and nobody owes anything,
 * so the ledger is never called. What it shares with the posters is the
 * shape — one transaction each, the document number taken inside it, every
 * figure worked out here whatever the form sent, and every step read again
 * under the row lock, so two people pressing Approve and Reject at the same
 * moment cannot both get through.
 *
 * The dashboard counts what is waiting, so each step flushes its cached
 * figures once the transaction has committed.
 */
class PurchaseRequestDesk
{
    public function __construct(
        protected DocumentNumber $numbers,
        protected AuditService $audit,
    ) {}

    /**
     * Raise a request, waiting for approval.
     *
     * A line may be typed in another unit — 5 boxes at 400 — and the
     * product's own unit is worked out beside it, the same way a purchase
     * does it, so the order made from it in phase 22 can copy the line.
     *
     * @param  array{supplier_id?:int|null, department_id?:int|null, needed_by?:string|null, reason:string, notes?:string|null, idempotency_key?:string|null}  $header
     * @param  list<array{product_id:int, unit_id?:int|null, quantity:int|float|string, unit_cost:float|string}>  $lines
     */
    public function raise(array $header, array $lines): PurchaseRequest
    {
        $lines = array_values($lines);
        $today = Carbon::today();

        return DB::transaction(function () use ($header, $lines, $today) {
            $priced = $this->price($lines);
            $reference = $this->numbers->next('documents.purchase_request_prefix', $today);

            $total = array_sum(array_map(fn (array $line) => Quantity::centavos($line['line_total']), $priced));

            $request = new PurchaseRequest([
                'reference' => $reference,
                'request_date' => $today,
                'needed_by' => $header['needed_by'] ?? null,
                'status' => PurchaseRequestStatus::Pending,
                'supplier_id' => $header['supplier_id'] ?? null,
                'department_id' => $header['department_id'] ?? null,
                'reason' => $header['reason'],
                'estimated_total' => $total / 100,
                'notes' => $header['notes'] ?? null,
                'idempotency_key' => $header['idempotency_key'] ?? null,
                'requested_by' => auth()->id(),
            ]);

            // One row in the trail that says everything, rather than the
            // observer's Create row with the lines still missing.
            $this->audit->withoutAuditing(fn () => $request->save());

            foreach ($priced as $line) {
                PurchaseRequestLine::create(['purchase_request_id' => $request->id] + $line);
            }

            $request->auditCustom(
                AuditAction::Create,
                "Raised purchase request [{$reference}]: ".count($priced).' line(s), estimated '
                    .number_format($total / 100, 2).'.',
                ['lines' => count($priced), 'estimated_total' => $total / 100],
            );

            $this->refreshTheFigures();

            return $request->fresh(['lines', 'supplier']);
        }, attempts: 3);
    }

    /** Say yes. Only to a pending request, and never to one's own. */
    public function approve(PurchaseRequest $request, User $by, ?string $note = null): PurchaseRequest
    {
        return $this->decide($request, $by, PurchaseRequestStatus::Approved, $note);
    }

    /** Say no, and why. */
    public function reject(PurchaseRequest $request, User $by, string $reason): PurchaseRequest
    {
        return $this->decide($request, $by, PurchaseRequestStatus::Rejected, $reason);
    }

    /**
     * Finish with a request nobody needs any more: pending or approved.
     *
     * Who may do it is the policy's question — the person who raised it, or
     * somebody who may approve. This only checks it is still open.
     */
    public function close(PurchaseRequest $request, User $by, string $reason): PurchaseRequest
    {
        return DB::transaction(function () use ($request, $by, $reason) {
            $locked = PurchaseRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw PurchaseRequestException::notOpen($locked->reference, strtolower($locked->status->label()));
            }

            $this->audit->withoutAuditing(fn () => $locked->forceFill([
                'status' => PurchaseRequestStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $by->id,
                'close_reason' => $reason,
            ])->save());

            $locked->auditCustom(
                AuditAction::Close,
                "Closed purchase request [{$locked->reference}]: {$reason}",
                ['reason' => $reason],
            );

            $this->refreshTheFigures();

            return $locked->fresh();
        }, attempts: 3);
    }

    protected function decide(PurchaseRequest $request, User $by, PurchaseRequestStatus $answer, ?string $note): PurchaseRequest
    {
        return DB::transaction(function () use ($request, $by, $answer, $note) {
            $locked = PurchaseRequest::whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                throw PurchaseRequestException::notPending($locked->reference, strtolower($locked->status->label()));
            }

            // The policy hides the button already. Asked again here, because
            // this is the rule decision 2 is about, and a rule only the
            // screen keeps is not kept.
            $own = $locked->wasRaisedBy($by);

            if ($own && ! PurchaseRequest::selfApprovalAllowed()) {
                throw PurchaseRequestException::ownRequest($locked->reference);
            }

            $note = trim((string) $note) ?: null;

            $this->audit->withoutAuditing(fn () => $locked->forceFill([
                'status' => $answer,
                'decided_at' => now(),
                'decided_by' => $by->id,
                'decision_note' => $note,
            ])->save());

            $approved = $answer === PurchaseRequestStatus::Approved;

            // Answering one's own request is allowed by a setting, and said
            // plainly in the trail, so it can always be found again.
            $locked->auditCustom(
                $approved ? AuditAction::Approve : AuditAction::Reject,
                ($approved ? 'Approved' : 'Rejected')
                    .($own ? ' their own' : '')
                    ." purchase request [{$locked->reference}]"
                    .($note ? ": {$note}" : '.'),
                ['note' => $note, 'estimated_total' => (float) $locked->estimated_total, 'own_request' => $own],
            );

            $this->refreshTheFigures();

            return $locked->fresh();
        }, attempts: 3);
    }

    /**
     * Every line as it is stored: what was typed, what that is in the
     * product's own unit, and the estimate.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{product_id:int, unit_id:?int, unit_factor:int|float, unit_quantity:int|float, quantity:string, unit_cost:float, line_total:float}>
     */
    protected function price(array $lines): array
    {
        $converted = UnitConversion::resolve($lines);
        $priced = [];

        foreach ($lines as $index => $line) {
            $unit = $converted[$index];

            if ($unit['error'] !== null || $unit['base'] === null || $unit['base'] <= 0) {
                throw PurchaseRequestException::cannotConvert(
                    $unit['error'] ?? 'A line could not be read in its product\'s own unit.'
                );
            }

            $typed = Quantity::normalize($line['quantity']);
            $cost = round((float) $line['unit_cost'], 2);

            $priced[] = [
                'product_id' => (int) $line['product_id'],
                'unit_id' => $unit['unit_id'],
                'unit_factor' => $unit['factor'],
                'unit_quantity' => $typed,
                'quantity' => Quantity::toDb($unit['base']),
                'unit_cost' => $cost,
                'line_total' => Quantity::lineTotal($typed, $cost),
            ];
        }

        return $priced;
    }

    /**
     * The dashboard counts what is waiting, so its cached figures go once
     * the change is safely committed. Inside the transaction another
     * request could cache the state from before it.
     */
    protected function refreshTheFigures(): void
    {
        DB::afterCommit(fn () => app(InventoryMetrics::class)->flush());
    }
}
