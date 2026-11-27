<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\DocumentStatus;
use App\Enums\MovementType;
use App\Exceptions\InventoryException;
use App\Exceptions\PaymentException;
use App\Exceptions\PendingPaymentException;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SalePayment;
use App\Support\CustomerCredit;
use App\Support\PaymentSettlement;
use App\Support\Quantity;
use App\Support\Reservations;
use App\Support\SaleTotals;
use App\Support\UnitConversion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Writes a sale and posts it in the same breath.
 *
 * The same shape as `PurchasePoster`: take the document number first, call
 * the ledger second, outermost transaction on `attempts: 3`.
 *
 * The difference is which way the stock goes. A sale eats the oldest batches
 * first, and what those batches cost becomes the cost of the sale — so the
 * margin is a fact, not an estimate.
 */
class SalePoster
{
    public function __construct(
        protected InventoryLedger $ledger,
        protected DocumentNumber $numbers,
        protected AuditService $audit,
        protected QuotationDesk $quotations,
        protected SalesOrderDesk $orders,
        protected PendingPaymentDesk $pending,
    ) {}

    /**
     * Record goods sold, and take them off the shelf.
     *
     * Every figure is worked out here. Whatever the form sent is ignored.
     *
     * A line may be typed in another unit — 1 box at 450 — and is priced as
     * typed, while the shelf gives up what that is in the product's own
     * unit: 100 pieces. The factor is read from the product, never from the
     * form, and copied onto the line.
     *
     * The payments are settled against the total worked out here, not one
     * the form sent: cash may be more than is owed and the rest is change,
     * nothing else may. Left out altogether, the sale is recorded as paid
     * exactly in cash (see `PaymentSettlement`).
     *
     * Part or all of it may go on the customer's account, within their
     * limit (see `CustomerCredit`). What is not paid now is the sale's
     * balance due, and falls due after the days agreed on this sale
     * (`terms_days`, phase 18.1), the customer's usual terms when none.
     *
     * @param  array{customer_id:int,sale_date:string|Carbon,discount?:float|string|null,mobilization?:float|string|null,on_account?:float|string|null,terms_days?:int|string|null,pending_payment_id?:int|null,notes?:string|null,idempotency_key?:string|null,payments?:list<array{payment_method_id:int,amount:float|string,reference?:string|null}>|null}  $header
     * @param  list<array{product_id:int,unit_id?:int|null,quantity:int|float|string,unit_price:float|string}>  $lines
     */
    public function post(array $header, array $lines): Sale
    {
        // Counted from 0, so each line meets its own conversion.
        $lines = array_values($lines);

        $date = $header['sale_date'] instanceof Carbon
            ? $header['sale_date']
            : Carbon::parse($header['sale_date']);

        $onAccount = Quantity::centavos(round((float) ($header['on_account'] ?? 0), 2));

        return DB::transaction(function () use ($header, $lines, $date, $onAccount) {
            $converted = $this->convert($lines);

            // A sale paying a pending payment (CR-2) locks it first and asks
            // again that it is waiting, for this customer, and for exactly
            // the lines, prices and discount the kiosk sent — so two cashiers
            // cannot both take the money, and nothing is re-priced.
            $pending = ! empty($header['pending_payment_id'])
                ? $this->pending->lockedForSale(
                    (int) $header['pending_payment_id'],
                    (int) $header['customer_id'],
                    null,
                    $lines,
                    $converted,
                    $header['discount'] ?? 0,
                )
                : null;

            // The till has no mobilization charge (phase 28.1), so a sale
            // paying what it sent adds none.
            if ($pending && Quantity::centavos(round((float) ($header['mobilization'] ?? 0), 2)) > 0) {
                throw PendingPaymentException::noMobilization($pending->reference);
            }

            // A sale made from a quotation (phase 28) locks the quotation
            // first and asks again that it is open, within its date and for
            // this customer, so two people cannot both turn it into a sale.
            $quotation = ! empty($header['quotation_id'])
                ? $this->quotations->lockedForSale((int) $header['quotation_id'], (int) $header['customer_id'], $date)
                : null;

            // A sale fulfilling a sales order (phase 29) locks the order the
            // same way, first; its own reservation is then its to take.
            $order = ! empty($header['sales_order_id'])
                ? $this->orders->lockedForSale((int) $header['sales_order_id'], (int) $header['customer_id'])
                : null;

            // Before the document number, so every poster still takes its
            // locks in one order. Only a sale on account takes this one: a
            // counter sale waits for nobody.
            $account = $onAccount > 0 ? $this->lockedAccount((int) $header['customer_id'], $onAccount) : null;

            // The days agreed on this sale (phase 18.1), weighed against
            // the customer's usual terms with the customer row locked.
            $termsDays = $account ? $this->agreedTerms($account, $header) : null;

            // Where the goods leave the shelf (phase 31.4), read with a
            // shared lock after the order and the account and before the
            // number — the place `PurchasePoster` reads its own.
            $location = $this->sellingLocation($header);

            // Paid where its goods are held, and nowhere else.
            if ($pending && (int) $pending->location_id !== (int) $location->id) {
                throw PendingPaymentException::otherPlace($pending->reference, (string) $pending->location?->name);
            }

            $reference = $this->numbers->next('documents.sale_prefix', $date);

            // Each line to the centavo first, in whole centavos, so the
            // subtotal is exactly the sum of the lines it prints.
            $totals = SaleTotals::of($lines, $header['discount'] ?? 0, $header['mobilization'] ?? 0);

            $paid = PaymentSettlement::settleSale($totals['total'], $onAccount, $header['payments'] ?? null);

            if ($paid['errors'] !== []) {
                throw PaymentException::unsettled(reset($paid['errors']));
            }

            $sale = new Sale([
                'reference' => $reference,
                'location_id' => $location->id,
                'customer_id' => $header['customer_id'],
                'sale_date' => $date,
                'status' => DocumentStatus::Posted,
                'subtotal' => $totals['subtotal'] / 100,
                'discount' => $totals['discount'] / 100,
                'mobilization' => $totals['mobilization'] / 100,
                'total_amount' => $totals['total'] / 100,
                'total_cost' => 0,
                'on_account' => $onAccount / 100,
                'balance_due' => $onAccount / 100,
                // Stamped now, so changing the terms later moves nothing.
                'due_date' => $account ? CustomerCredit::dueDate($account, $date, $termsDays) : null,
                'terms_days' => $termsDays,
                'notes' => $header['notes'] ?? null,
                'idempotency_key' => $header['idempotency_key'] ?? null,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            $this->audit->withoutAuditing(fn () => $sale->save());

            foreach ($paid['lines'] as $payment) {
                SalePayment::create([
                    'sale_id' => $sale->id,
                    'payment_method_id' => $payment['payment_method_id'],
                    'amount' => $payment['amount'] / 100,
                    'tendered' => $payment['tendered'] === null ? null : $payment['tendered'] / 100,
                    'reference' => $payment['reference'],
                ]);
            }

            foreach ($lines as $index => $line) {
                $typed = Quantity::normalize($line['quantity']);
                $unitPrice = round((float) $line['unit_price'], 2);

                SaleLine::create([
                    'sale_id' => $sale->id,
                    'product_id' => $line['product_id'],
                    'unit_id' => $converted[$index]['unit_id'],
                    'unit_factor' => $converted[$index]['factor'],
                    'unit_quantity' => $typed,
                    'quantity' => Quantity::toDb($converted[$index]['base']),
                    'unit_price' => $unitPrice,
                    'line_total' => Quantity::lineTotal($typed, $unitPrice),
                    'unit_cost' => 0,
                    'line_cost' => 0,
                ]);
            }

            // The ledger refuses the whole document if any line asks for more
            // than there is, so overselling cannot get through.
            $movements = $this->ledger->post(
                $sale,
                $reference,
                $date,
                MovementType::Sale,
                array_map(fn (array $line, int $index) => [
                    'product_id' => (int) $line['product_id'],
                    // In the product's own unit: what leaves the shelf.
                    'quantity' => Quantity::toDb($converted[$index]['base']),
                ], $lines, array_keys($lines)),
                $location,
            );

            $cost = $this->copyCostsBack($sale, $movements);

            $sale->forceFill(['total_cost' => $cost])->saveQuietly();

            // The ledger holds the product rows now. What is left on the
            // shelf must still cover what is reserved for other customers.
            $taken = [];

            foreach ($lines as $index => $line) {
                $taken[(int) $line['product_id']] = ($taken[(int) $line['product_id']] ?? 0) + $converted[$index]['base'];
            }

            $this->refuseTakingReservedStock($taken, $order?->id, $location, $pending?->id);

            if ($quotation) {
                $this->quotations->accept($quotation, $sale);
            }

            if ($order) {
                $this->orders->fulfil($order, $sale);
            }

            if ($pending) {
                $this->pending->markPaid($pending, $sale);
            }

            $sale->auditCustom(
                AuditAction::Post,
                "Posted sale [{$reference}]: ".count($lines).' line(s), '
                    .number_format($sale->total_amount, 2).'.',
                // The cost of goods is deliberately absent. The audit page
                // shows these properties to anybody holding audit.view, and
                // what a sale cost sits behind sales.view_cost everywhere
                // else — a figure hidden on one screen must not arrive on
                // another.
                [
                    'lines' => count($lines),
                    'total_amount' => (float) $sale->total_amount,
                    'paid_by' => $sale->payments()->with('method:id,name')->get()
                        ->map(fn (SalePayment $p) => $p->method?->name.' '.number_format((float) $p->amount, 2))
                        ->implode(', '),
                    'change' => $paid['change'] / 100,
                    'on_account' => $onAccount / 100,
                    'due_date' => $sale->due_date?->toDateString(),
                    'terms_days' => $sale->terms_days,
                    'quotation' => $quotation?->reference,
                    'sales_order' => $order?->reference,
                    'pending_payment' => $pending?->reference,
                ],
            );

            return $sale->fresh(['lines', 'customer', 'payments.method']);
        }, attempts: 3);
    }

    /**
     * Where the goods leave the shelf (phase 31.4).
     *
     * A form that sends no location at all — a tab opened before this phase
     * — sells from the default, where every sale sold until now. The form
     * request has already refused an empty or switched-off one; this asks
     * again under a shared lock, so the location cannot be switched off
     * between the check and the posting.
     *
     * @param  array<string, mixed>  $header
     */
    protected function sellingLocation(array $header): Location
    {
        if (empty($header['location_id'])) {
            return Location::default() ?? throw InventoryException::noDefaultLocation();
        }

        $location = Location::whereKey($header['location_id'])->sharedLock()->first();

        if (! $location || ! $location->is_active) {
            throw new InventoryException('The location picked for this sale has been switched off. Pick another one.');
        }

        return $location;
    }

    /**
     * Refuse a sale that took stock reserved for somebody else (phase 29).
     *
     * Asked after the ledger has moved the stock, while it holds the product
     * rows, so no approval can slip in between; what other orders hold is
     * read with a shared lock. The whole sale rolls back if any product is
     * now below what is reserved.
     *
     * Since phase 31.4 at the sale's own location: what is left there
     * against what orders hold there. A reservation at another place is
     * not this sale's business.
     *
     * @param  array<int, int>  $taken  Product id => thousandths this sale took.
     */
    protected function refuseTakingReservedStock(array $taken, ?int $fulfilling, Location $location, ?int $paying = null): void
    {
        // The pending payment this sale pays (CR-2) may take its own hold.
        $held = Reservations::reserved(array_keys($taken), $fulfilling, locking: true, locationId: $location->id, exceptPending: $paying);

        if ($held === []) {
            return;
        }

        // Read after the ledger wrote them, in this transaction.
        $there = Reservations::atLocation(array_keys($held), $location->id, locking: true);
        $products = Product::query()->whereIn('id', array_keys($held))->get(['id', 'sku']);

        foreach ($products as $product) {
            $left = $there[$product->id] ?? 0;

            if ($left < $held[$product->id]) {
                // What was free before this sale took its share.
                $free = max(0, $left + ($taken[$product->id] ?? 0) - $held[$product->id]);

                throw InventoryException::heldForOthers($product->sku, Quantity::format(Quantity::fromMilli($free)));
            }
        }
    }

    /**
     * The customer, held still while their credit is weighed.
     *
     * The form request has already asked this, so the error could sit under
     * the box. This is the answer that counts: another till may have charged
     * the same customer in between.
     */
    protected function lockedAccount(int $customerId, int $onAccount): Customer
    {
        $customer = Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();

        // A locking read of what is owed, not only of the customer: see owed().
        $refusal = CustomerCredit::refusal($customer, $onAccount, CustomerCredit::owed($customerId, locking: true));

        if ($refusal !== null) {
            throw PaymentException::unsettled($refusal);
        }

        return $customer;
    }

    /**
     * How many days this sale on account is given (phase 18.1).
     *
     * None sent — a form from before this phase, or an empty box — is the
     * customer's usual terms, as every sale was until now. More than usual
     * needs `customers.credit`; the form request has already said so under
     * the box, and this is the answer that counts.
     *
     * @param  array<string, mixed>  $header
     */
    protected function agreedTerms(Customer $customer, array $header): int
    {
        $sent = $header['terms_days'] ?? null;

        if ($sent === null || $sent === '') {
            return (int) $customer->payment_terms_days;
        }

        $refusal = CustomerCredit::termsRefusal(
            $customer,
            (int) $sent,
            (bool) auth()->user()?->can('customers.credit'),
        );

        if ($refusal !== null) {
            throw PaymentException::unsettled($refusal);
        }

        return (int) $sent;
    }

    /**
     * Every line in the product's own unit, or the whole sale refused.
     *
     * The form request has already said which line is wrong. Reaching this
     * means a product's units changed in between, and the sale must not go
     * through on a factor nobody saw.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<int, array{unit_id:?int, factor:int|float, base:int}>
     */
    protected function convert(array $lines): array
    {
        $converted = UnitConversion::resolve($lines);

        foreach ($converted as $line) {
            if ($line['error'] !== null || $line['base'] === null) {
                throw InventoryException::cannotConvert($line['error'] ?? 'A line could not be read in its product\'s own unit.');
            }
        }

        return $converted;
    }

    /**
     * Put the stock back and mark the sale void.
     *
     * Unlike a purchase, there is nothing to check first: the goods have
     * come back, so they go back into the batches they left.
     */
    public function void(Sale $sale, string $reason): Sale
    {
        return DB::transaction(function () use ($sale, $reason) {
            $locked = Sale::whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPosted()) {
                throw InventoryException::alreadyVoided($locked->reference);
            }

            // A void puts every unit back. If some are already back through a
            // return, they would be counted twice and the stock would come
            // apart from its batches.
            if ($locked->hasReturns()) {
                throw InventoryException::alreadyReturned($locked->reference);
            }

            // Money came in against it. Voiding the sale would leave that
            // payment paying an invoice that no longer exists, so the
            // payment is voided first, on purpose, by somebody who means it.
            // A locking read, for the same reason as `CustomerCredit::owed()`.
            if ($locked->hasLivePayments(locking: true)) {
                throw InventoryException::hasPayments($locked->reference);
            }

            $this->ledger->reverseIssue($locked, $locked->reference, Carbon::today());

            $this->audit->withoutAuditing(fn () => $locked->forceFill([
                'status' => DocumentStatus::Voided,
                'voided_at' => now(),
                'voided_by' => auth()->id(),
                'void_reason' => $reason,
                // The goods are back, so nothing is owed for them.
                'balance_due' => 0,
            ])->save());

            $locked->auditCustom(
                AuditAction::Void,
                "Voided sale [{$locked->reference}]: {$reason}",
                ['reason' => $reason],
            );

            return $locked->fresh(['lines', 'customer']);
        }, attempts: 3);
    }

    /**
     * What each line really cost, once the ledger knows which batches it ate.
     *
     * @param  list<InventoryMovement>  $movements
     */
    protected function copyCostsBack(Sale $sale, array $movements): float
    {
        $total = 0.0;
        $lines = $sale->lines()->orderBy('id')->get();

        foreach ($movements as $index => $movement) {
            $line = $lines[$index] ?? null;

            if (! $line) {
                continue;
            }

            $line->forceFill([
                // Kept so a return can find the exact batches this line ate.
                'inventory_movement_id' => $movement->id,
                'unit_cost' => $movement->unit_cost,
                'line_cost' => $movement->total_cost,
            ])->save();

            $total += (float) $movement->total_cost;
        }

        return round($total, 2);
    }
}
