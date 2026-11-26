<?php

namespace App\Enums;

/**
 * How the screens look for one person (phase 35.1, CR-3).
 *
 * Light is the white screen the app always had; dark is the same app with
 * dark surfaces and light text; system follows the phone's or computer's own
 * setting, resolved in the browser because the server cannot see it.
 *
 * Stored on `users.appearance`; null there means "the default", which is
 * Settings -> Theme Settings -> Default appearance. Paper and the sign-in
 * pages are always light, whatever this says.
 */
enum Appearance: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Light => 'Light',
            self::Dark => 'Dark',
            self::System => 'Same as my device',
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
     * For the settings schema: value => label.
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        return array_combine(
            self::values(),
            array_map(fn (self $appearance) => $appearance->label(), self::cases()),
        );
    }
}
