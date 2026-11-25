<?php

namespace App\Casts;

use App\Support\Quantity;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A quantity column: read as 10 or 2.5, written as "2.500".
 *
 * Not `decimal:3`, which would hand every screen and payload "10.000". A
 * whole quantity stays an int, so what was 10 before decimals arrived is
 * still 10 everywhere it is read.
 */
class QuantityCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): int|float|null
    {
        return $value === null ? null : Quantity::normalize($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Quantity::toDb(Quantity::toMilli($value));
    }
}
