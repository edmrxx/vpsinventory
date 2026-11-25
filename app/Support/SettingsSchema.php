<?php

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * Reads config/settings.php.
 *
 * The group list, the fields, their types, defaults and validation rules all
 * come from here, so the settings page, the form request and the manager stay
 * in step with one file.
 */
class SettingsSchema
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function groups(): array
    {
        return config('settings.groups', []);
    }

    public static function hasGroup(string $group): bool
    {
        return array_key_exists($group, static::groups());
    }

    /**
     * @return list<string>
     */
    public static function groupKeys(): array
    {
        return array_keys(static::groups());
    }

    /**
     * The fields of one group, keyed by the full setting key.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fieldsOf(string $group): array
    {
        return Arr::get(static::groups(), "{$group}.fields", []);
    }

    /**
     * One field's declaration.
     *
     * @return array<string, mixed>
     */
    public static function field(string $key): array
    {
        foreach (static::groups() as $group) {
            if (isset($group['fields'][$key])) {
                return $group['fields'][$key];
            }
        }

        return [];
    }

    /**
     * Every setting key the schema knows.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        $keys = [];

        foreach (static::groups() as $group) {
            $keys = array_merge($keys, array_keys($group['fields'] ?? []));
        }

        return $keys;
    }

    public static function default(string $key): mixed
    {
        return static::field($key)['default'] ?? null;
    }

    public static function type(string $key): string
    {
        return static::field($key)['type'] ?? 'text';
    }

    /**
     * Is this field a picture?
     *
     * A picture is uploaded through its own route and written by
     * `SettingImage`. The Save button never carries it: the form still holds
     * the value from when the page opened, and saving that would put the old
     * picture back over a fresh upload.
     */
    public static function isImage(string $key): bool
    {
        return static::type($key) === 'image';
    }

    /**
     * The picture fields of one group, by full key.
     *
     * @return list<string>
     */
    public static function imageKeysOf(string $group): array
    {
        return array_values(array_filter(
            array_keys(static::fieldsOf($group)),
            fn (string $key) => static::isImage($key)
        ));
    }

    /**
     * Turn a stored string back into the type the schema declares.
     */
    public static function cast(string $key, ?string $raw): mixed
    {
        if ($raw === null) {
            return static::default($key);
        }

        return match (static::type($key)) {
            'toggle' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
            'number' => str_contains($raw, '.') ? (float) $raw : (int) $raw,
            'json' => json_decode($raw, true) ?? [],
            default => $raw,
        };
    }

    /**
     * The name a field is posted under.
     *
     * A setting key holds a dot, and Laravel's validator reads a dot as a
     * step into a nested array. So the form posts the short name —
     * `values[name]` rather than `values[company.name]` — and the server
     * puts the group back on afterwards.
     */
    public static function shortName(string $group, string $key): string
    {
        return str_starts_with($key, "{$group}.")
            ? substr($key, strlen($group) + 1)
            : $key;
    }

    /**
     * The full setting key for a posted short name, or null when the group
     * does not declare it.
     */
    public static function fullKey(string $group, string $short): ?string
    {
        foreach (array_keys(static::fieldsOf($group)) as $key) {
            if (static::shortName($group, $key) === $short) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Validation rules for one group, keyed the way the form posts them.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(string $group): array
    {
        $rules = [];

        foreach (static::fieldsOf($group) as $key => $field) {
            // Not posted by the form at all. See isImage().
            if (static::isImage($key)) {
                continue;
            }

            $rules['values.'.static::shortName($group, $key)] = $field['rules'] ?? ['nullable'];
        }

        return $rules;
    }

    /**
     * Readable names for the validation messages, so an error reads
     * "The company name is required" instead of using the raw key.
     *
     * @return array<string, string>
     */
    public static function attributesFor(string $group): array
    {
        $names = [];

        foreach (static::fieldsOf($group) as $key => $field) {
            $names['values.'.static::shortName($group, $key)] = strtolower($field['label'] ?? $key);
        }

        return $names;
    }

    /**
     * The groups, shaped for the settings screen navigation.
     *
     * @return list<array{key:string,label:string,description:string,icon:string,field_count:int}>
     */
    public static function forNavigation(): array
    {
        $groups = [];

        foreach (static::groups() as $key => $group) {
            $groups[] = [
                'key' => $key,
                'label' => $group['label'] ?? ucfirst($key),
                'description' => $group['description'] ?? '',
                'icon' => $group['icon'] ?? 'Settings',
                'field_count' => count($group['fields'] ?? []),
            ];
        }

        return $groups;
    }

    /**
     * One group's fields, shaped for the form.
     *
     * @return list<array<string, mixed>>
     */
    public static function forForm(string $group): array
    {
        $fields = [];

        foreach (static::fieldsOf($group) as $key => $field) {
            $options = [];

            foreach ($field['options'] ?? [] as $value => $label) {
                $options[] = ['value' => $value, 'label' => $label];
            }

            $fields[] = [
                // `key` is the real setting key, shown as a label hint and
                // used for the input id. `name` is what the form posts.
                'key' => $key,
                'name' => static::shortName($group, $key),
                'label' => $field['label'] ?? $key,
                'type' => $field['type'] ?? 'text',
                'hint' => $field['hint'] ?? null,
                'options' => $options,
                'width' => $field['width'] ?? 'half',
                'required' => in_array('required', (array) ($field['rules'] ?? []), true),
                // Where a picture field uploads to, and is removed from.
                'upload_url' => static::isImage($key)
                    ? route('settings.images.store', [$group, static::shortName($group, $key)], false)
                    : null,
            ];
        }

        return $fields;
    }
}
