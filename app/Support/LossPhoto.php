<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The photos kept as evidence on a loss report (phase 33).
 *
 * The only place one is written or deleted. Unlike a product photo, evidence
 * is not for the shop floor: it sits on the **private** disk
 * (`storage/app/private/losses`), never reachable by an address, and is
 * served only through `LossReportController::photo()` to somebody who may
 * read the report — reading the path from the report's own photo row, never
 * from the request.
 *
 * Shrunk with GD like a product photo (`ImageFile`), which also turns the
 * picture upright. A JPEG — what a phone takes — is always written out
 * again, which drops the camera's notes, the GPS position among them; a
 * small upright PNG or WebP is kept as it came.
 * JPG, PNG or WebP only: SVG is XML that can carry script.
 */
class LossPhoto
{
    /** The longest edge a stored picture may have, in pixels. */
    public const MAX_EDGE = 1600;

    /** The folder on the private disk. A backup carries it (BackupMaker). */
    public const DIRECTORY = 'losses';

    /** How many photos one report may carry. */
    public const MAX_PER_REPORT = 5;

    /** The upload rules, shared by every form that takes one. */
    public const RULES = ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'];

    public static function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    /** Save an upload under a random name, shrunk, and return its path. */
    public static function store(UploadedFile $file): string
    {
        $path = $file->store(static::DIRECTORY, 'local');

        ImageFile::shrink(static::disk()->path($path), static::MAX_EDGE);

        return $path;
    }

    /** The file on disk for a stored path, or null when it is not one of ours. */
    public static function file(?string $path): ?string
    {
        if (! static::owns($path) || ! static::disk()->exists($path)) {
            return null;
        }

        return static::disk()->path($path);
    }

    /**
     * Remove the photos these paths name, and return how many were there.
     * Only paths under `losses/`, never the folder: the disk is not tied to
     * one database.
     *
     * @param  iterable<string|null>  $paths
     */
    public static function deleteMany(iterable $paths): int
    {
        $deleted = 0;

        foreach ($paths as $path) {
            if (static::owns($path) && static::disk()->exists($path)) {
                static::disk()->delete($path);
                $deleted++;
            }
        }

        return $deleted;
    }

    protected static function owns(?string $path): bool
    {
        return $path !== null
            && str_starts_with($path, static::DIRECTORY.'/')
            && ! str_contains($path, '..');
    }
}
