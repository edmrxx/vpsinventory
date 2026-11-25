<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Requests\HeldSale\StoreHeldSaleRequest;
use App\Models\HeldSale;
use App\Models\Sale;
use App\Support\Quantity;
use App\Support\SaleTotals;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Hold and recall at the till.
 *
 * Three actions and no screen of their own: the holds are listed on the
 * till, under the Held tab. All three sit behind `kiosk.use` like the till
 * itself, and behind `sales.create` here, because parking a sale nobody
 * could finish would be a strange thing to allow.
 *
 * Nothing in here touches stock. A hold is a basket kept on the server; the
 * sale it becomes is posted through `POST /sales`, by the same request and
 * the same poster as every other.
 */
class HeldSaleController extends Controller
{
    /** Park what is on the counter. */
    public function store(StoreHeldSaleRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Quantities are kept as text, so 2.5 m comes back as 2.5 m and
        // never as 2.4999.
        $lines = array_map(fn (array $line) => [
            'product_id' => (int) $line['product_id'],
            'unit_id' => isset($line['unit_id']) ? (int) $line['unit_id'] : null,
            'quantity' => (string) Quantity::normalize($line['quantity']),
            'unit_price' => self::pesos(Quantity::centavos(round((float) $line['unit_price'], 2))),
        ], $data['lines']);

        $totals = SaleTotals::of($lines, $data['discount'] ?? 0);

        HeldSale::create([
            'customer_id' => $data['customer_id'] ?? null,
            'note' => isset($data['note']) ? trim($data['note']) ?: null : null,
            'discount' => self::pesos($totals['discount']),
            'total_amount' => self::pesos($totals['total']),
            'lines' => $lines,
            'held_by' => $request->user()->id,
        ]);

        return back()->with('success', 'The sale is on hold. Find it under Held.');
    }

    /**
     * Bring a held sale back to the counter.
     *
     * The row is claimed by deleting it. Two tablets tapping Recall on the
     * same hold at the same moment both find it, but only one delete can
     * remove a row — the other is told it has gone, rather than both
     * counters ringing up the same order.
     */
    public function recall(Request $request, int $held): RedirectResponse
    {
        $hold = $this->claim($request, $held);

        $hold->auditCustom(
            AuditAction::Delete,
            "Recalled held sale [{$hold->auditLabel()}] to the counter.",
            ['lines' => count($hold->lines ?? []), 'total_amount' => $hold->total_amount],
        );

        return back()->with('recalled', [
            'id' => $hold->id,
            'customer_id' => $hold->customer_id,
            'note' => $hold->note,
            'discount' => $hold->discount,
            'lines' => $hold->lines,
        ]);
    }

    /** Throw a held sale away: the customer is not coming back. */
    public function destroy(Request $request, int $held): RedirectResponse
    {
        $hold = $this->claim($request, $held);

        $hold->auditCustom(
            AuditAction::Delete,
            "Discarded held sale [{$hold->auditLabel()}].",
            ['lines' => count($hold->lines ?? []), 'total_amount' => $hold->total_amount],
        );

        return back()->with('success', 'The held sale was discarded.');
    }

    /**
     * Take the hold off the shelf, or say it is already gone.
     *
     * A plain delete on the key, so no `deleted` event fires and the
     * caller writes the one trail row that says what really happened.
     */
    protected function claim(Request $request, int $id): HeldSale
    {
        $this->authorize('create', Sale::class);

        $hold = HeldSale::query()->find($id);

        if (! $hold || HeldSale::query()->whereKey($id)->delete() === 0) {
            throw ValidationException::withMessages([
                'hold' => 'That held sale was already recalled or discarded on another screen.',
            ]);
        }

        return $hold;
    }

    protected static function pesos(int $centavos): string
    {
        return number_format($centavos / 100, 2, '.', '');
    }
}
