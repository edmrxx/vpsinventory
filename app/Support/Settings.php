<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The settings manager.
 *
 * Every stored value is loaded once per request from one cached array, so a
 * page reading twenty settings still costs no query. Saving a value clears
 * the cache.
 *
 * What a setting means lives in config/settings.php. This class only moves
 * values in and out, casting them to the type the schema declares.
 */
class Settings
{
    /** The loaded values, so one request reads the cache once. */
    protected ?array $values = null;

    /**
     * Read one setting. Falls back to the schema default, then to $default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->raw()[$key] ?? null;

        if ($raw === null) {
            return $default ?? SettingsSchema::default($key);
        }

        return SettingsSchema::cast($key, $raw);
    }

    /**
     * Every setting, cast, with defaults filled in for anything unsaved.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = $this->raw();

        $values = [];

        foreach (SettingsSchema::keys() as $key) {
            $values[$key] = array_key_exists($key, $stored)
                ? SettingsSchema::cast($key, $stored[$key])
                : SettingsSchema::default($key);
        }

        // Keys that are in the table but no longer in the schema are still
        // readable, so removing a field never loses data.
        foreach ($stored as $key => $raw) {
            $values[$key] ??= $raw;
        }

        return $values;
    }

    /**
     * The values of one group, keyed by the short name.
     *
     * `group('company')` returns ['name' => ..., 'email' => ...], which is
     * what the front end wants.
     *
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        $values = [];

        foreach (SettingsSchema::fieldsOf($group) as $key => $field) {
            $short = str_starts_with($key, "{$group}.")
                ? substr($key, strlen($group) + 1)
                : $key;

            $values[$short] = $this->get($key);
        }

        return $values;
    }

    /**
     * The values of one group, keyed by the full setting key.
     *
     * @return array<string, mixed>
     */
    public function groupByKey(string $group): array
    {
        $values = [];

        foreach (array_keys(SettingsSchema::fieldsOf($group)) as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * Save one setting.
     */
    public function set(string $key, mixed $value): void
    {
        $this->setMany([$key => $value]);
    }

    /**
     * Save several settings in one go, then clear the cache once.
     *
     * @param  array<string, mixed>  $values
     */
    public function setMany(array $values): void
    {
        if ($values === []) {
            return;
        }

        DB::transaction(function () use ($values) {
            foreach ($values as $key => $value) {
                Setting::updateOrCreate(
                    ['key' => $key],
                    ['value' => $this->serialise($value)]
                );
            }
        });

        $this->flush();
    }

    /**
     * Put a group back to the defaults in the schema.
     */
    public function resetGroup(string $group): void
    {
        $keys = array_keys(SettingsSchema::fieldsOf($group));

        if ($keys === []) {
            return;
        }

        Setting::whereIn('key', $keys)->delete();

        $this->flush();
    }

    /**
     * Drop the cache, so the next read goes to the table.
     */
    public function flush(): void
    {
        $this->values = null;

        Cache::forget(config('settings.cache_key', 'settings.all'));
    }

    /**
     * Has anybody saved a value for this key?
     */
    public function isStored(string $key): bool
    {
        return array_key_exists($key, $this->raw());
    }

    /* ------------------------------------------------------------ Internal */

    /**
     * The raw stored strings, from the cache.
     *
     * @return array<string, ?string>
     */
    protected function raw(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        return $this->values = Cache::remember(
            config('settings.cache_key', 'settings.all'),
            config('settings.cache_ttl', 86400),
            fn () => Setting::query()->pluck('value', 'key')->all()
        );
    }

    protected function serialise(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        return $value === null ? null : (string) $value;
    }
}
