<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The one place a picture held by a setting is written or thrown away.
 *
 * Today that is the company logo. A field declared with `'type' => 'image'`
 * in config/settings.php is uploaded through its own route and lands here;
 * the setting then holds the path a browser loads it from, so `BrandMark`
 * and everything else that reads the setting needs no change.
 *
 * The file sits on the `public` disk under `settings/`, with a random name.
 * Like a product photo it needs `storage:link`, and a database export does
 * not carry it.
 *
 * A field that also declares `'makes_icons' => true` gets the browser tab
 * icon and the phone home-screen icon cut from the same upload, under fixed
 * names under `settings/icons/`, so `app.blade.php` can find them without a
 * setting of their own.
 */
class SettingImage
{
    public const DIRECTORY = 'settings';

    /** Kept apart from the uploads, so an icon is never taken for one. */
    public const ICON_DIRECTORY = 'settings/icons';

    /** The longest edge a stored picture may have, in pixels. */
    public const MAX_EDGE = 512;

    /** The square icons, by name: file on the disk, size, background. */
    protected const ICONS = [
        'favicon' => ['file' => 'favicon.png', 'size' => 64, 'background' => null],
        'apple-touch-icon' => ['file' => 'apple-touch-icon.png', 'size' => 180, 'background' => [255, 255, 255]],
    ];

    /**
     * Save an upload for a setting and return the value to store: the path
     * a browser loads it from.
     */
    public static function store(string $key, UploadedFile $file): string
    {
        $path = $file->store(static::DIRECTORY, 'public');
        $absolute = Storage::disk('public')->path($path);

        ImageFile::shrink($absolute, static::MAX_EDGE);

        if (static::makesIcons($key)) {
            static::makeIcons($absolute);
        }

        return static::url($path);
    }

    /**
     * Remove the file a setting value points at — but only one this class
     * wrote. The default logo under `public/images` ships with the system
     * and is never touched, and a value that does not look exactly like one
     * of ours is left alone rather than trusted as a path to delete.
     */
    public static function delete(?string $value): void
    {
        $path = static::pathFrom($value);

        if ($path !== null && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * The setting has stopped using this picture: remove the file, and the
     * icons cut from it when the field makes them.
     */
    public static function discard(string $key, ?string $value): void
    {
        // Only an upload brought icons with it. A value that was never ours
        // — the shipped default, or nothing — means the icons on disk belong
        // to somebody else's upload, or to no setting at all, and stay put.
        if (! static::isUploaded($value)) {
            return;
        }

        static::delete($value);

        if (static::makesIcons($key)) {
            foreach (static::ICONS as $icon) {
                Storage::disk('public')->delete(static::ICON_DIRECTORY.'/'.$icon['file']);
            }
        }
    }

    /** Was this value written by an upload, rather than typed or shipped? */
    public static function isUploaded(?string $value): bool
    {
        return static::pathFrom($value) !== null;
    }

    /**
     * Where the browser loads an icon from, or null when there is none.
     *
     * The file's age is added to the address, so a browser that kept the
     * old tab icon fetches the new one after a fresh upload.
     */
    public static function iconUrl(string $name): ?string
    {
        $file = static::ICONS[$name]['file'] ?? null;

        if ($file === null) {
            return null;
        }

        $path = static::ICON_DIRECTORY.'/'.$file;
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return null;
        }

        return static::url($path).'?v='.$disk->lastModified($path);
    }

    /**
     * The path from the site root, not the absolute address. `Storage::url()`
     * builds that from `APP_URL`, and a wrong `APP_URL` would break the logo
     * while everything else kept working. Same reasoning as `ProductPhoto`.
     */
    public static function url(string $path): string
    {
        $url = Storage::disk('public')->url($path);

        return parse_url($url, PHP_URL_PATH) ?: $url;
    }

    /* ------------------------------------------------------------ Internal */

    protected static function makesIcons(string $key): bool
    {
        return (bool) (SettingsSchema::field($key)['makes_icons'] ?? false);
    }

    protected static function makeIcons(string $source): void
    {
        $disk = Storage::disk('public');

        // GD writes to a path and will not make the folder itself.
        $disk->makeDirectory(static::ICON_DIRECTORY);

        foreach (static::ICONS as $icon) {
            $target = static::ICON_DIRECTORY.'/'.$icon['file'];

            // A stale icon from the last logo is worse than none: without
            // it the tab falls back to the shipped icon instead.
            if (! ImageFile::square($source, $disk->path($target), $icon['size'], $icon['background'])) {
                $disk->delete($target);
            }
        }
    }

    /**
     * The disk path behind a stored value, or null when the value is not a
     * picture this class wrote.
     *
     * The match is strict on purpose — a random name of letters and digits
     * directly under `settings/`, nothing else — because the result is
     * handed to `delete()`. A value such as `/storage/settings/../products/x`
     * must never reach it.
     */
    protected static function pathFrom(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $prefix = rtrim(static::url(''), '/').'/';

        if (! str_starts_with($value, $prefix)) {
            return null;
        }

        $path = substr($value, strlen($prefix));

        return preg_match('#^'.static::DIRECTORY.'/[A-Za-z0-9]+\.(jpe?g|png|webp)$#', $path)
            ? $path
            : null;
    }
}
