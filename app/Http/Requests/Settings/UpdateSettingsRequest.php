<?php

namespace App\Http\Requests\Settings;

use App\Support\SettingsSchema;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    /**
     * The rules come from config/settings.php, so a new field is validated
     * the moment it is declared.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(
            ['values' => ['required', 'array']],
            SettingsSchema::rulesFor($this->group())
        );
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return SettingsSchema::attributesFor($this->group());
    }

    /**
     * A toggle posts true or false, and an empty text box posts null rather
     * than an empty string, so a cleared field falls back to its default.
     */
    protected function prepareForValidation(): void
    {
        $group = $this->group();
        $values = (array) $this->input('values', []);

        foreach (SettingsSchema::fieldsOf($group) as $key => $field) {
            $short = SettingsSchema::shortName($group, $key);

            if (($field['type'] ?? 'text') === 'toggle') {
                // An unticked toggle is not posted at all, so it is read as
                // false rather than left missing.
                $values[$short] = filter_var(
                    $values[$short] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                );

                continue;
            }

            if (is_string($values[$short] ?? null)) {
                $values[$short] = trim($values[$short]);
            }
        }

        $this->merge(['values' => $values]);
    }

    /**
     * The validated values, keyed by the real setting key.
     *
     * Anything the group does not declare is dropped, so a crafted request
     * cannot write a setting that belongs to another group or to no group.
     * A picture field is dropped too, so a crafted request cannot point the
     * logo at a path of its choosing either.
     *
     * @return array<string, mixed>
     */
    public function settingValues(): array
    {
        $group = $this->group();
        $posted = (array) ($this->validated()['values'] ?? []);

        $values = [];

        foreach ($posted as $short => $value) {
            $key = SettingsSchema::fullKey($group, (string) $short);

            // A picture is never saved from here: the form still holds the
            // value from when the page opened. See SettingsSchema::isImage().
            if ($key !== null && ! SettingsSchema::isImage($key)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    public function group(): string
    {
        return (string) $this->route('group');
    }
}
