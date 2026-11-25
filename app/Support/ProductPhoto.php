<?php

namespace App\Support;

use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The one place a product photo is written, shrunk or thrown away.
 *
 * Every other part of the system only ever holds the path. Putting the
 * file handling in one class is the same rule the figures live by: a
 * second copy of "where does this go, and what is a safe size" would
 * drift, and the copy that drifted would be the one leaving files behind.
 *
 * A picture is stored on the `public` disk under `products/`, with a
 * random name. The original name is never used: it reaches us from the
 * browser and can hold anything. The pixel work — turning, shrinking,
 * stripping the camera's notes — is `ImageFile`, shared with the logo.
 */
class ProductPhoto
{
    /** The longest edge a stored picture may have, in pixels. */
    public const MAX_EDGE = 1200;

    /** The folder on the `public` disk that holds every product photo. */
    public const DIRECTORY = 'products';

    /**
     * Save an upload and return its path, tidied.
     *
     * A phone takes a four-megabyte photograph, usually on its side. Left
     * alone, the counter tablet would download thirty of them to draw one
     * screen of tiles, which on mobile data is the difference between
     * usable and not, and half of them would be sideways.
     */
    public static function store(UploadedFile $file): string
    {
        $path = $file->store(static::DIRECTORY, 'public');

        ImageFile::shrink(Storage::disk('public')->path($path), static::MAX_EDGE);

        return $path;
    }

    /**
     * Save a picture that is already on disk, such as a sample shipped with
     * a seeder, exactly as an upload is saved: a random name, then shrunk.
     */
    public static function storeFromFile(string $file): string
    {
        $path = Storage::disk('public')->putFile(static::DIRECTORY, new File($file));

        ImageFile::shrink(Storage::disk('public')->path($path), static::MAX_EDGE);

        return $path;
    }

    /**
     * Replace one photo with another, and remove what was there before.
     *
     * The old file is only deleted once the new one is safely written, so
     * a failed upload never leaves a product with a picture that is gone.
     */
    public static function replace(?string $existing, UploadedFile $file): string
    {
        $path = static::store($file);

        static::delete($existing);

        return $path;
    }

    /** Remove a stored photo. Safe to call with nothing. */
    public static function delete(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Remove the photos these paths name, and return how many were there.
     *
     * Only the files a product row actually named, never the whole folder:
     * the disk is not tied to one database, so a folder sweep run against a
     * copy of the data would take the real photos with it.
     *
     * @param  iterable<string|null>  $paths
     */
    public static function deleteMany(iterable $paths): int
    {
        $disk = Storage::disk('public');
        $deleted = 0;

        foreach ($paths as $path) {
            if ($path && str_starts_with($path, static::DIRECTORY.'/') && $disk->exists($path)) {
                $disk->delete($path);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Where a browser loads the photo from, as a path from the site root.
     *
     * Deliberately not the absolute address. `Storage::url()` builds that
     * from `APP_URL`, and an `APP_URL` left at its default silently breaks
     * every picture on every screen while the rest of the system carries
     * on working — which is a bad half-hour for whoever has to find it. A
     * path from the root is right whatever the domain is.
     *
     * The API sends the absolute address instead, because a phone cannot
     * resolve a path on its own. `ProductResource` makes it, from the host
     * the request actually arrived on.
     */
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        // Taken from the disk's own URL rather than written out here, so a
        // disk configured with a different prefix still works.
        return parse_url(Storage::disk('public')->url($path), PHP_URL_PATH)
            ?: Storage::disk('public')->url($path);
    }
}
