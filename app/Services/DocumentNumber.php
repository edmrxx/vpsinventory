<?php

namespace App\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The next reference for a document, for example PR-2026-0001.
 *
 * The counter lives in its own table and is locked while a number is taken,
 * so two people saving at the same moment cannot be handed the same one.
 * The unique index on each document's `reference` is the second guard.
 *
 * Prefix, padding and the yearly reset are settings, so a business that
 * numbers differently changes a box instead of the code.
 */
class DocumentNumber
{
    /**
     * Take the next number for a kind of document.
     *
     * Must be called inside the same transaction as the document it names,
     * or a rolled-back save would burn a number.
     *
     * @param  string  $setting  The settings key holding the prefix,
     *                           e.g. "documents.purchase_prefix".
     */
    public function next(string $setting, ?Carbon $date = null): string
    {
        // No fallback argument here on purpose: Settings::get() lets one
        // win over the default declared in config/settings.php, which would
        // quietly bypass the setting the user can actually see and change.
        $prefix = strtoupper(trim((string) settings($setting))) ?: 'DOC';
        $padding = max(1, (int) settings('documents.number_padding'));
        $yearly = (bool) settings('documents.reset_yearly');

        $date ??= Carbon::today();
        $year = $yearly ? (int) $date->format('Y') : 0;

        $number = $this->claim($prefix, $year);
        $running = str_pad((string) $number, $padding, '0', STR_PAD_LEFT);

        return $yearly
            ? "{$prefix}-{$year}-{$running}"
            : "{$prefix}-{$running}";
    }

    /**
     * Read the counter, hand back its value and move it on, with the row
     * locked for the whole of it.
     */
    protected function claim(string $prefix, int $year): int
    {
        $row = DB::table('document_sequences')
            ->where('prefix', $prefix)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if (! $row) {
            // Two first-ever saves could both land here, so the unique index
            // decides it and the loser reads the row the winner wrote.
            try {
                DB::table('document_sequences')->insert([
                    'prefix' => $prefix,
                    'year' => $year,
                    'next_number' => 2,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return 1;
            } catch (UniqueConstraintViolationException) {
                $row = DB::table('document_sequences')
                    ->where('prefix', $prefix)
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->first();
            }
        }

        $number = (int) $row->next_number;

        DB::table('document_sequences')
            ->where('id', $row->id)
            ->update(['next_number' => $number + 1, 'updated_at' => now()]);

        return $number;
    }
}
