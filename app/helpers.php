<?php

use App\Support\Settings;

if (! function_exists('settings')) {
    /**
     * Read a setting, or reach the manager with no arguments.
     *
     *     settings('company.name')            the stored value, or its default
     *     settings('company.name', 'Fallback')
     *     settings()->set('company.name', 'X')
     *     settings()->group('theme')
     */
    function settings(?string $key = null, mixed $default = null): mixed
    {
        $manager = app(Settings::class);

        return $key === null ? $manager : $manager->get($key, $default);
    }
}
