<?php

namespace App\Http\Controllers;

use App\Enums\AuditAction;
use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Http\Requests\Settings\UploadSettingImageRequest;
use App\Services\AuditService;
use App\Support\SettingImage;
use App\Support\Settings;
use App\Support\SettingsSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One screen for every settings group.
 *
 * The page is built from config/settings.php, so adding a field there is all
 * it takes for it to appear, be validated and be saved.
 */
class SettingsController extends Controller
{
    public function __construct(
        protected Settings $settings,
        protected AuditService $audit,
    ) {}

    /**
     * Send the first group, so /settings always lands somewhere useful.
     */
    public function index(): RedirectResponse
    {
        $first = SettingsSchema::groupKeys()[0] ?? null;

        abort_if($first === null, 404);

        return redirect()->route('settings.show', $first);
    }

    public function show(Request $request, string $group): Response
    {
        if (! SettingsSchema::hasGroup($group)) {
            throw new NotFoundHttpException("There is no [{$group}] settings group.");
        }

        return Inertia::render('Settings/Show', [
            'title' => 'Settings',
            'groups' => SettingsSchema::forNavigation(),
            'group' => [
                'key' => $group,
                'label' => SettingsSchema::groups()[$group]['label'] ?? $group,
                'description' => SettingsSchema::groups()[$group]['description'] ?? '',
                'icon' => SettingsSchema::groups()[$group]['icon'] ?? 'Settings',
            ],
            'fields' => SettingsSchema::forForm($group),
            // Keyed by the short name, which is what the form posts back.
            'values' => $this->settings->group($group),
            'can' => [
                'update' => $request->user()->can('settings.update'),
            ],
        ]);
    }

    public function update(UpdateSettingsRequest $request, string $group): RedirectResponse
    {
        if (! SettingsSchema::hasGroup($group)) {
            throw new NotFoundHttpException("There is no [{$group}] settings group.");
        }

        $values = $request->settingValues();
        $before = $this->settings->groupByKey($group);

        $this->settings->setMany($values);

        $this->logChanges($group, $before, $values);

        return back()->with('success', 'Settings saved.');
    }

    /**
     * Put one group back to the defaults declared in the schema.
     */
    public function reset(string $group): RedirectResponse
    {
        if (! SettingsSchema::hasGroup($group)) {
            throw new NotFoundHttpException("There is no [{$group}] settings group.");
        }

        $before = $this->settings->groupByKey($group);

        $this->settings->resetGroup($group);

        // An uploaded picture is no longer used once its setting is back at
        // the default, so its file goes too rather than lingering on disk.
        foreach (SettingsSchema::imageKeysOf($group) as $key) {
            SettingImage::discard($key, $before[$key] ?? null);
        }

        $this->logChanges($group, $before, $this->settings->groupByKey($group), reset: true);

        return back()->with('success', 'Settings put back to the defaults.');
    }

    /**
     * Upload a picture for an image field, such as the company logo.
     *
     * It is saved at once rather than with the Save button: a file cannot
     * wait in a form across page loads, and the Save button never carries a
     * picture field (see SettingsSchema::isImage()).
     */
    public function uploadImage(UploadSettingImageRequest $request, string $group, string $field): RedirectResponse
    {
        $key = $this->imageKey($group, $field);
        $before = $this->settings->get($key);

        $this->settings->set($key, SettingImage::store($key, $request->file('image')));

        // Only once the new one is safely saved, so a failure never leaves
        // the setting pointing at a file that is gone.
        SettingImage::delete($before);

        $this->logChanges($group, [$key => $before], [$key => $this->settings->get($key)]);

        return back()->with('success', $this->fieldLabel($key).' updated.');
    }

    /**
     * Take the picture away. The setting is left empty rather than put back
     * to its default, so the initials show — which is what "remove" means
     * to whoever pressed it. "Put back to the defaults" restores the default.
     */
    public function removeImage(string $group, string $field): RedirectResponse
    {
        $key = $this->imageKey($group, $field);
        $before = $this->settings->get($key);

        $this->settings->set($key, '');

        SettingImage::discard($key, $before);

        $this->logChanges($group, [$key => $before], [$key => '']);

        return back()->with('success', $this->fieldLabel($key).' removed.');
    }

    /**
     * The full key of a picture field in a group, or a 404.
     */
    protected function imageKey(string $group, string $field): string
    {
        if (! SettingsSchema::hasGroup($group)) {
            throw new NotFoundHttpException("There is no [{$group}] settings group.");
        }

        $key = SettingsSchema::fullKey($group, $field);

        if ($key === null || ! SettingsSchema::isImage($key)) {
            throw new NotFoundHttpException("[{$group}] has no picture field named [{$field}].");
        }

        return $key;
    }

    protected function fieldLabel(string $key): string
    {
        return SettingsSchema::field($key)['label'] ?? 'Picture';
    }

    /**
     * Write one audit row naming the fields that really changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    protected function logChanges(
        string $group,
        array $before,
        array $after,
        bool $reset = false,
    ): void {
        $changed = [];

        foreach ($after as $key => $value) {
            $old = $before[$key] ?? null;

            // Loose comparison on purpose: a toggle arrives as a boolean and
            // leaves the table as "1".
            if ((string) $old === (string) $value) {
                continue;
            }

            $changed[$key] = ['from' => $old, 'to' => $value];
        }

        if ($changed === []) {
            return;
        }

        $label = SettingsSchema::groups()[$group]['label'] ?? $group;

        $this->audit->log(
            AuditAction::Update,
            'Settings',
            ($reset ? "Put [{$label}] back to the defaults: " : "Updated [{$label}]: ")
                .implode(', ', array_keys($changed)).'.',
            null,
            ['changed' => $changed],
        );
    }
}
