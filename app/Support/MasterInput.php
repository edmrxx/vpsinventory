<?php

namespace App\Support;

/**
 * The tidying every master form does before its rules run.
 *
 * Codes such as a SKU or a supplier code are stored in capitals. MySQL, which
 * this app runs on, compares text without regard to case, while SQLite, which
 * the tests run on, does not. Folding the case here makes "abc" and "ABC" the
 * same value on both, so a duplicate is caught in development as well as in
 * production.
 *
 * An empty box is stored as null rather than as an empty string, so a blank
 * value reads the same whichever form saved it.
 *
 *     $this->merge(MasterInput::clean($this->all(), [
 *         'sku' => 'upper',
 *         'name' => 'trim',
 *     ]));
 */
class MasterInput
{
    /**
     * @param  array<string, mixed>  $input  The raw request data.
     * @param  array<string, string>  $fields  Field name => 'trim' | 'upper' | 'lower'.
     * @return array<string, mixed>
     */
    public static function clean(array $input, array $fields): array
    {
        $cleaned = [];

        foreach ($fields as $field => $how) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $value = $input[$field];

            // An array or an object is left alone: it is not a text box, and
            // the rules will refuse it on their own.
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $value = trim((string) $value);

            $value = match ($how) {
                'upper' => mb_strtoupper($value),
                'lower' => mb_strtolower($value),
                default => $value,
            };

            $cleaned[$field] = $value === '' ? null : $value;
        }

        return $cleaned;
    }
}
