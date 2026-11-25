<?php

namespace App\Support;

use App\Models\PurchaseRequest;

/**
 * The Abstract of Quotation (phase 24.2): every supplier's price for every
 * item of one purchase request, side by side, the lowest marked.
 *
 * The only place prices are compared. The request's page and its printed
 * abstract read `of()`; the award is weighed by `weighAward()` against the
 * same table — the form asks it for errors, `CanvassDesk` asks again with the
 * request row locked and decides. Money is compared in whole centavos.
 *
 * A supplier is a column, in the order its quote was recorded; each item a
 * row, whose `cells` line up with the columns (null where that supplier did
 * not quote the item). Every tie for the lowest price is marked lowest, and
 * choosing any of them needs no reason.
 */
final class QuoteAbstract
{
    /**
     * @return array{suppliers: list<array<string, mixed>>, lines: list<array<string, mixed>>, quoted: bool, lowest_total: string, awarded_total: ?string}
     */
    public static function of(PurchaseRequest $request): array
    {
        $request->loadMissing([
            'lines' => fn ($q) => $q->orderBy('id'),
            'lines.product:id,sku,name,unit_id',
            'lines.product.unit:id,code',
            'lines.unit:id,code',
            'supplierQuotes' => fn ($q) => $q->orderBy('id'),
            'supplierQuotes.supplier:id,name,code',
            'supplierQuotes.lines',
            'supplierQuotes.recordedBy:id,name',
        ]);

        $quotes = $request->supplierQuotes->values();

        $suppliers = $quotes->map(fn ($quote) => [
            'quote_id' => $quote->id,
            'supplier_id' => $quote->supplier_id,
            'name' => $quote->supplier?->name,
            'code' => $quote->supplier?->code,
            'delivery_days' => $quote->delivery_days,
            'payment_terms' => $quote->payment_terms,
            'notes' => $quote->notes,
            'recorded_by' => $quote->recordedBy?->name,
            'recorded_at' => $quote->created_at?->format('d M Y, g:i A'),
            'cents' => 0,
            'items_quoted' => 0,
            'lowest_count' => 0,
        ])->all();

        $lines = [];
        $lowestTotal = 0;
        $awardedTotal = 0;
        $anyAward = false;

        foreach ($request->lines as $line) {
            $cells = [];

            foreach ($quotes as $index => $quote) {
                $priced = $quote->lines->firstWhere('purchase_request_line_id', $line->id);

                $cells[$index] = $priced ? [
                    'quote_line_id' => $priced->id,
                    'unit_price' => $priced->unit_price,
                    'line_total' => $priced->line_total,
                    'price_cents' => Quantity::centavos($priced->unit_price),
                    'total_cents' => Quantity::centavos($priced->line_total),
                    'lowest' => false,
                ] : null;
            }

            $prices = array_filter(array_column(array_filter($cells), 'price_cents'), fn ($c) => $c !== null);
            $lowest = $prices === [] ? null : min($prices);
            $award = null;

            foreach ($cells as $index => $cell) {
                if ($cell === null) {
                    continue;
                }

                $cells[$index]['lowest'] = $cell['price_cents'] === $lowest;
                $suppliers[$index]['cents'] += $cell['total_cents'];
                $suppliers[$index]['items_quoted']++;

                if ($cells[$index]['lowest']) {
                    $suppliers[$index]['lowest_count']++;
                }

                if ($line->awarded_quote_line_id !== null && (int) $line->awarded_quote_line_id === $cell['quote_line_id']) {
                    $award = [
                        'column' => $index,
                        'quote_line_id' => $cell['quote_line_id'],
                        'supplier' => $suppliers[$index]['name'],
                        'unit_price' => $cell['unit_price'],
                        'line_total' => $cell['line_total'],
                        'lowest' => $cells[$index]['lowest'],
                        'reason' => $line->award_reason,
                    ];
                    $awardedTotal += $cell['total_cents'];
                    $anyAward = true;
                }
            }

            if ($lowest !== null) {
                $lowestTotal += min(array_column(array_filter($cells), 'total_cents'));
            }

            $lines[] = [
                'line_id' => $line->id,
                'name' => $line->product?->name,
                'sku' => $line->product?->sku,
                'quantity_label' => UnitConversion::describe(
                    $line->unit_quantity,
                    $line->unit?->code,
                    $line->quantity,
                    $line->product?->unit?->code,
                    $line->unit_factor,
                ),
                'unit_code' => $line->unit?->code ?? $line->product?->unit?->code,
                'quoted' => $lowest !== null,
                'lowest_price' => $lowest === null ? null : number_format($lowest / 100, 2, '.', ''),
                'cells' => array_map(fn ($cell) => $cell === null ? null : [
                    'quote_line_id' => $cell['quote_line_id'],
                    'unit_price' => $cell['unit_price'],
                    'line_total' => $cell['line_total'],
                    'lowest' => $cell['lowest'],
                ], array_values($cells)),
                'award' => $award,
            ];
        }

        return [
            'suppliers' => array_map(function (array $supplier) {
                $supplier['total'] = number_format($supplier['cents'] / 100, 2, '.', '');
                unset($supplier['cents']);

                return $supplier;
            }, $suppliers),
            'lines' => $lines,
            'quoted' => $quotes->isNotEmpty(),
            'lowest_total' => number_format($lowestTotal / 100, 2, '.', ''),
            'awarded_total' => $anyAward ? number_format($awardedTotal / 100, 2, '.', '') : null,
        ];
    }

    /**
     * What is wrong with an award, keyed for the form: `choices.{line}` and
     * `reasons.{line}`. Empty when it stands.
     *
     * Every item somebody quoted needs a supplier; an item nobody quoted is
     * left out of the award (the abstract says so). A supplier dearer than
     * the lowest needs a reason (the client's answer to Q3). One supplier per
     * item (Q4): a choice is one quote line.
     *
     * @param  array<int|string, int|string|null>  $choices  request line id => quote line id
     * @param  array<int|string, string|null>  $reasons  request line id => why not the lowest
     * @return array<string, string>
     */
    public static function weighAward(array $abstract, array $choices, array $reasons): array
    {
        if (! $abstract['quoted']) {
            return ['choices' => 'No supplier has quoted yet. Record at least one quote first.'];
        }

        $errors = [];

        foreach ($abstract['lines'] as $line) {
            if (! $line['quoted']) {
                continue;
            }

            $id = $line['line_id'];
            $chosen = $choices[$id] ?? $choices[(string) $id] ?? null;
            $index = null;

            foreach ($line['cells'] as $column => $cell) {
                if ($cell !== null && (int) $cell['quote_line_id'] === (int) $chosen) {
                    $index = $column;
                }
            }

            if ($chosen === null || $chosen === '' || $index === null) {
                $errors["choices.{$id}"] = "Choose a supplier for {$line['name']}.";

                continue;
            }

            if (! $line['cells'][$index]['lowest']) {
                $reason = trim((string) ($reasons[$id] ?? $reasons[(string) $id] ?? ''));

                if (mb_strlen($reason) < 3) {
                    $supplier = $abstract['suppliers'][$index]['name'];
                    $errors["reasons.{$id}"] = "Say why {$supplier} for {$line['name']}, when another supplier quoted lower.";
                }
            }
        }

        return $errors;
    }
}
