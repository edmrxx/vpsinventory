<?php

namespace App\Support;

/**
 * Turns the theme settings into CSS variables.
 *
 * The result is printed into the page head by the server, before any
 * stylesheet or script runs, so the app is never seen in the wrong colour
 * for a moment.
 *
 * It only overrides the tokens declared in resources/css/app.css, so the
 * design system stays the one source of truth for everything else.
 */
class ThemeStyles
{
    /**
     * The whole ramp is mixed from the one chosen colour, so any hex gives a
     * set of shades that belong together.
     *
     * Each entry is [percentage of the brand colour, colour mixed into it].
     */
    protected const RAMP = [
        '50' => [8, 'white'],
        '100' => [16, 'white'],
        '200' => [30, 'white'],
        '300' => [50, 'white'],
        '400' => [74, 'white'],
        '500' => [90, 'white'],
        '600' => [100, null],
        '700' => [86, 'black'],
        '800' => [70, 'black'],
        '900' => [56, 'black'],
    ];

    protected const RADIUS = [
        'sharp' => ['field' => '0.25rem', 'card' => '0.375rem', 'panel' => '0.5rem'],
        'rounded' => ['field' => '0.625rem', 'card' => '0.875rem', 'panel' => '1.125rem'],
        'soft' => ['field' => '0.875rem', 'card' => '1.25rem', 'panel' => '1.5rem'],
    ];

    public function __construct(protected Settings $settings) {}

    /**
     * The CSS to print in the head. Empty when every setting is on default.
     */
    public function css(): string
    {
        $declarations = array_merge(
            $this->brandDeclarations(),
            $this->radiusDeclarations(),
        );

        if ($declarations === []) {
            return '';
        }

        return ':root{'.implode('', $declarations).'}';
    }

    /**
     * Attributes for the <html> tag, so CSS can react to the theme without
     * waiting for Vue.
     *
     * @return array<string, string>
     */
    public function htmlAttributes(): array
    {
        $attributes = [
            'data-density' => $this->settings->get('theme.density', 'comfortable'),
        ];

        if (! $this->settings->get('theme.show_page_transitions', true)) {
            $attributes['data-transitions'] = 'off';
        }

        return $attributes;
    }

    /**
     * @return list<string>
     */
    protected function brandDeclarations(): array
    {
        $base = $this->settings->get('theme.brand_color');

        if (! is_string($base) || ! preg_match('/^#[0-9A-Fa-f]{6}$/', $base)) {
            return [];
        }

        if (strtolower($base) === strtolower((string) SettingsSchema::default('theme.brand_color'))) {
            // Still the default, so the tokens in app.css already apply.
            return [];
        }

        $declarations = [];

        foreach (self::RAMP as $step => [$weight, $mixWith]) {
            $declarations[] = "--color-brand-{$step}:".(
                $mixWith === null
                    ? $base
                    : "color-mix(in oklab, {$base} {$weight}%, {$mixWith})"
            ).';';
        }

        // The brand shadow is tinted with the same colour.
        $declarations[] = '--shadow-brand:'
            ."0 1px 2px 0 color-mix(in oklab, {$base} 22%, transparent),"
            ."0 8px 20px -6px color-mix(in oklab, {$base} 38%, transparent);";

        return $declarations;
    }

    /**
     * @return list<string>
     */
    protected function radiusDeclarations(): array
    {
        $choice = $this->settings->get('theme.radius', 'rounded');

        if ($choice === 'rounded' || ! isset(self::RADIUS[$choice])) {
            return [];
        }

        $radius = self::RADIUS[$choice];

        return [
            "--radius-field:{$radius['field']};",
            "--radius-card:{$radius['card']};",
            "--radius-panel:{$radius['panel']};",
        ];
    }
}
