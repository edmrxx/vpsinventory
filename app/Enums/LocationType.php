<?php

namespace App\Enums;

/**
 * What kind of place a location is (phase 31.1).
 *
 * A store sells over the counter; a warehouse holds stock that is not on
 * display. The kind changes nothing yet — it names the place on the list.
 * Phase 31.4 will read it to decide where the till sells from.
 */
enum LocationType: string
{
    case Store = 'store';
    case Warehouse = 'warehouse';

    public function label(): string
    {
        return match ($this) {
            self::Store => 'Store',
            self::Warehouse => 'Warehouse',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type) => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
