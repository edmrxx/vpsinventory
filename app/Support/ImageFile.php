<?php

namespace App\Support;

/**
 * The picture work the system does to a file on disk: turn it upright,
 * shrink it, strip the camera's notes, and cut a square icon from it.
 *
 * `ProductPhoto` and `SettingImage` decide where a file goes and when it is
 * thrown away; this class only ever changes pixels. Both use the one copy,
 * so a fix to how a picture is shrunk reaches both.
 *
 * It uses GD, which ships with PHP, rather than a package: this system is
 * deployed by uploading files by hand, so a new `vendor/` folder is a cost
 * on every release. Anything GD on this server cannot do is simply left
 * alone — a picture kept as it arrived is worse than a tidy one, not broken.
 */
class ImageFile
{
    /**
     * Put a picture right in place: upright, no longer than `$maxEdge` on
     * its longest side, and with the camera's own notes stripped off.
     */
    public static function shrink(string $file, int $maxEdge): void
    {
        $info = @getimagesize($file);

        if ($info === false) {
            return;
        }

        [$width, $height] = $info;
        $type = $info[2];

        $turn = static::orientation($file, $type);

        if (! static::canHandle($type) || ! static::fitsInMemory($width, $height, $turn !== 0, $maxEdge)) {
            return;
        }

        // A picture that is already small, upright and not a JPEG has
        // nothing to gain from being written out again.
        if ($turn === 0 && max($width, $height) <= $maxEdge && $type !== IMAGETYPE_JPEG) {
            return;
        }

        $source = static::open($file, $type);

        if (! $source) {
            return;
        }

        // Turning comes first, so the long edge is measured on the picture
        // as it will actually be seen.
        if ($turn !== 0) {
            $turned = @imagerotate($source, $turn, 0);

            if ($turned) {
                imagedestroy($source);
                $source = $turned;
                $width = imagesx($source);
                $height = imagesy($source);
            }
        }

        $longest = max($width, $height);
        $scale = $longest > $maxEdge ? $maxEdge / $longest : 1.0;

        $target = imagecreatetruecolor((int) round($width * $scale), (int) round($height * $scale));

        // A PNG or a WebP may be see-through. Without this the clear parts
        // come out black, which looks like a broken upload.
        if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
        }

        imagecopyresampled(
            $target, $source,
            0, 0, 0, 0,
            imagesx($target), imagesy($target), $width, $height
        );

        // Written out fresh, which is also what drops the camera's EXIF
        // block — and with it the place the photograph was taken. A
        // picture has no business carrying somebody's home address.
        match ($type) {
            IMAGETYPE_JPEG => imagejpeg($target, $file, 82),
            IMAGETYPE_PNG => imagepng($target, $file, 6),
            IMAGETYPE_WEBP => imagewebp($target, $file, 82),
            default => null,
        };

        imagedestroy($source);
        imagedestroy($target);
    }

    /**
     * Write a square PNG of `$size` pixels with the picture centred in it.
     *
     * A browser tab icon is square. Handed a wide logo, the browser squashes
     * it; centring it in a square keeps its shape. `$background` is an RGB
     * triple, or null for see-through — an iPhone fills see-through parts of
     * a home-screen icon with black, so that one is given white.
     *
     * Returns false when GD cannot do it, and then writes nothing.
     *
     * @param  array{0:int,1:int,2:int}|null  $background
     */
    public static function square(string $source, string $target, int $size, ?array $background = null): bool
    {
        $info = @getimagesize($source);

        if ($info === false || ! function_exists('imagepng')) {
            return false;
        }

        [$width, $height] = $info;
        $type = $info[2];

        if (! static::canHandle($type) || ! static::fitsInMemory($width, $height, false, $size)) {
            return false;
        }

        $picture = static::open($source, $type);

        if (! $picture) {
            return false;
        }

        $canvas = imagecreatetruecolor($size, $size);
        imagesavealpha($canvas, true);

        if ($background === null) {
            // Copied without blending, so each pixel keeps its own
            // see-through value instead of being mixed into black.
            imagealphablending($canvas, false);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        } else {
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, ...$background));
            imagealphablending($canvas, true);
        }

        // A little room round the edge, so the mark does not touch it.
        $inner = $size * 0.9;
        $scale = min($inner / $width, $inner / $height);
        $drawWidth = max(1, (int) round($width * $scale));
        $drawHeight = max(1, (int) round($height * $scale));

        imagecopyresampled(
            $canvas, $picture,
            intdiv($size - $drawWidth, 2), intdiv($size - $drawHeight, 2), 0, 0,
            $drawWidth, $drawHeight, $width, $height
        );

        $written = imagepng($canvas, $target, 6);

        imagedestroy($picture);
        imagedestroy($canvas);

        return $written;
    }

    /* ------------------------------------------------------------ Internal */

    /** @return \GdImage|false|null */
    protected static function open(string $file, int $type): mixed
    {
        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_PNG => @imagecreatefrompng($file),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file),
            default => null,
        };
    }

    /**
     * How far to turn the picture, in degrees.
     *
     * A phone almost always saves a portrait shot as landscape pixels plus
     * a note saying "turn this". A browser reads that note; GD does not,
     * and writes none of its own. Without this every portrait photograph
     * would be upright in the dialog and on its side once saved.
     */
    protected static function orientation(string $file, int $type): int
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return 0;
        }

        $exif = @exif_read_data($file);

        return match ($exif['Orientation'] ?? 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
    }

    /** Is this GD build able to read and write this kind of picture? */
    protected static function canHandle(int $type): bool
    {
        // An absent GD function is a fatal error, not something `@` hides,
        // so each one is asked for by name. A GD built without WebP is
        // common on shared hosting.
        return match ($type) {
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') && function_exists('imagejpeg'),
            IMAGETYPE_PNG => function_exists('imagecreatefrompng') && function_exists('imagepng'),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') && function_exists('imagewebp'),
            default => false,
        };
    }

    /**
     * Is there room to open this picture?
     *
     * GD holds every pixel uncompressed, four bytes each. A twelve-megapixel
     * photograph is about 48 MB the moment it is opened. Turning one costs a
     * second full-size copy, because the turned image is built beside the
     * original before the original is freed; shrinking does not, because
     * what is being drawn into is small.
     *
     * Running out of memory is a fatal error, and the file is already stored
     * by the time this runs, so it would leave a picture on disk and no row
     * pointing at it. A picture too big for this server is therefore kept
     * exactly as it arrived.
     */
    protected static function fitsInMemory(int $width, int $height, bool $turning, int $maxEdge): bool
    {
        $limit = static::memoryLimit();

        if ($limit <= 0) {
            return true;
        }

        $source = $width * $height * 4;
        $target = $maxEdge * $maxEdge * 4;

        $needed = ($turning ? $source * 2 : $source + $target) * 1.15;

        return ($needed + memory_get_usage(true)) < $limit;
    }

    protected static function memoryLimit(): int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return 0;
        }

        $size = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $size * 1024 * 1024 * 1024,
            'm' => $size * 1024 * 1024,
            'k' => $size * 1024,
            default => $size,
        };
    }
}
