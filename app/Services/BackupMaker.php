<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\User;
use App\Support\DatabaseDump;
use App\Support\LossPhoto;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Makes, lists and deletes backups (phase 35).
 *
 * A backup is one .zip on the private disk (`storage/app/private/backups`),
 * never the public one:
 *
 * - `database.sql` — every table, at one consistent moment (`DatabaseDump`);
 * - `files/` — the product photos and the uploaded logo, read through the
 *   `public` disk, when Settings → Backup and Export says to include them;
 * - `private/losses/` — the photos kept as evidence on loss reports (phase
 *   33), from the private disk, under the same switch;
 * - `RESTORE.txt` — how to put it back;
 * - `manifest.json` — when, by whom, and how much.
 *
 * **It never carries `.env`.** The app key and the database password are
 * kept apart from the books, so a stolen backup does not open the server.
 * It does carry the users' password hashes and the whole books, which is why
 * only `backups.*` — Admin — may touch one, and every one made, downloaded
 * or deleted is written to the audit trail.
 *
 * A backup is written to a `.part` name and renamed only once it is whole,
 * so a half-made one is never listed; the oldest are pruned only after a new
 * one succeeds. Restoring is done by hand, never from the app: see
 * RESTORE.txt and README, "Backups".
 */
class BackupMaker
{
    public const DIRECTORY = 'backups';

    /** What a backup's name must look like. Nothing else is ever served. */
    public const NAME = '/^backup-\d{4}-\d{2}-\d{2}-\d{6}(-\d+)?\.zip$/';

    public function __construct(protected AuditService $audit) {}

    public static function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    public static function validName(string $name): bool
    {
        return (bool) preg_match(self::NAME, $name);
    }

    /**
     * Make a backup now. `$by` is null when the scheduler makes it.
     *
     * @return array{name:string, size:int, tables:int, rows:int, files:int}
     */
    public function make(?User $by = null): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The server\'s PHP has no zip extension, so a backup cannot be made. Ask the host to switch on "zip".');
        }

        $disk = self::disk();
        $disk->makeDirectory(self::DIRECTORY);

        $name = $this->freshName();
        $final = $disk->path(self::DIRECTORY.'/'.$name);
        $partial = $final.'.part';
        $sql = $final.'.sql';

        try {
            $counts = (new DatabaseDump(DB::connection()))->writeTo($sql);
            $files = $this->zip($partial, $sql, $counts, $by);

            rename($partial, $final);
        } finally {
            @unlink($sql);
            @unlink($partial);
        }

        $size = (int) filesize($final);

        $this->audit->log(
            AuditAction::Create,
            'Backups',
            "Made backup [{$name}]: {$counts['tables']} tables, ".number_format($counts['rows']).' rows, '
                .$files.' file(s), '.self::readableSize($size).($by === null ? ', on schedule.' : '.'),
            null,
            ['name' => $name, 'size' => $size],
            $by,
        );

        $this->prune();

        return ['name' => $name, 'size' => $size, 'tables' => $counts['tables'], 'rows' => $counts['rows'], 'files' => $files];
    }

    /**
     * Every backup on the server, newest first.
     *
     * @return list<array{name:string, size:int, size_label:string, made_at:string}>
     */
    public function all(): array
    {
        $disk = self::disk();

        return collect($disk->files(self::DIRECTORY))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $name) => self::validName($name))
            // Newest first: by the time in the name, then by the -2, -3 a
            // second backup in the same second carries.
            ->sortByDesc(fn (string $name) => substr($name, 7, 17).sprintf('%04d', (int) (preg_match('/^backup-[\d-]{17}-(\d+)\.zip$/', $name, $m) ? $m[1] : 1)))
            ->values()
            ->map(fn (string $name) => [
                'name' => $name,
                'size' => $size = (int) $disk->size(self::DIRECTORY.'/'.$name),
                'size_label' => self::readableSize($size),
                'made_at' => Carbon::createFromTimestamp($disk->lastModified(self::DIRECTORY.'/'.$name))
                    ->timezone(config('app.timezone'))
                    ->format('d M Y g:i A'),
            ])
            ->all();
    }

    /** The full path of an existing backup, or null. */
    public function path(string $name): ?string
    {
        if (! self::validName($name) || ! self::disk()->exists(self::DIRECTORY.'/'.$name)) {
            return null;
        }

        return self::disk()->path(self::DIRECTORY.'/'.$name);
    }

    public function delete(string $name, User $by): bool
    {
        if ($this->path($name) === null) {
            return false;
        }

        self::disk()->delete(self::DIRECTORY.'/'.$name);

        $this->audit->log(AuditAction::Delete, 'Backups', "Deleted backup [{$name}].", null, ['name' => $name], $by);

        return true;
    }

    /** Keep the newest copies the setting allows; delete the rest. */
    protected function prune(): void
    {
        $keep = max(1, (int) settings('backup.keep_copies', 7));

        foreach (array_slice($this->all(), $keep) as $old) {
            self::disk()->delete(self::DIRECTORY.'/'.$old['name']);

            $this->audit->log(AuditAction::Delete, 'Backups', "Removed the old backup [{$old['name']}]: only the newest {$keep} are kept.", null, ['name' => $old['name']]);
        }
    }

    /** @param  array{tables:int, rows:int}  $counts */
    protected function zip(string $path, string $sql, array $counts, ?User $by): int
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The backup file could not be written.');
        }

        $zip->addFile($sql, 'database.sql');

        $files = 0;

        if ((bool) settings('backup.include_files', true)) {
            $public = Storage::disk('public');

            foreach ($public->allFiles() as $file) {
                // Only what people uploaded; never a dotfile or a link.
                if (str_starts_with(basename($file), '.')) {
                    continue;
                }

                $zip->addFile($public->path($file), 'files/'.$file);
                $files++;
            }

            // The evidence on loss reports (phase 33) is on the private disk,
            // never the public one. Only its own folder: the private disk
            // also holds the backups themselves and the exports.
            $private = LossPhoto::disk();

            foreach ($private->allFiles(LossPhoto::DIRECTORY) as $file) {
                if (str_starts_with(basename($file), '.')) {
                    continue;
                }

                $zip->addFile($private->path($file), 'private/'.$file);
                $files++;
            }
        }

        $zip->addFromString('manifest.json', json_encode([
            'system' => 'GO ENTERPRISES INVENTORY',
            'made_at' => now()->toIso8601String(),
            'made_by' => $by ? $by->name.' (@'.$by->username.')' : 'the scheduler',
            'database' => DB::connection()->getDriverName(),
            'tables' => $counts['tables'],
            'rows' => $counts['rows'],
            'files' => $files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $zip->addFromString('RESTORE.txt', $this->restoreNote($files));

        if (! $zip->close()) {
            throw new RuntimeException('The backup file could not be finished.');
        }

        return $files;
    }

    protected function restoreNote(int $files): string
    {
        return <<<TXT
        GO ENTERPRISES INVENTORY — how to restore this backup
        =====================================================

        This backup holds the whole books and every user's password hash.
        Keep it somewhere only the owner can reach. Keep a copy OFF the
        server: a backup on the same server is lost with the server.

        It does NOT hold the .env file. Keep the .env (APP_KEY and the
        database password) separately; the restored system needs the same
        APP_KEY.

        1. Put the site in maintenance:   php artisan down
        2. Make an EMPTY database (or empty the old one), then import
           database.sql into it — phpMyAdmin > Import, or:
               mysql -u USER -p DATABASE < database.sql
        3. Copy the files/ folder into storage/app/public on the server,
           keeping its folders (products/, settings/). If there is a
           private/ folder, copy its losses/ folder into
           storage/app/private (the evidence on loss reports).
           {$files} file(s) in all.
        4. php artisan storage:link   (if the photos show broken)
        5. php artisan optimize:clear
        6. php artisan inventory:verify   — it must say every check passed.
        7. php artisan up

        TXT;
    }

    protected function freshName(): string
    {
        $base = 'backup-'.now()->timezone(config('app.timezone'))->format('Y-m-d-His');
        $name = $base.'.zip';

        for ($n = 2; self::disk()->exists(self::DIRECTORY.'/'.$name); $n++) {
            $name = "{$base}-{$n}.zip";
        }

        return $name;
    }

    public static function readableSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_073_741_824 => number_format($bytes / 1_073_741_824, 1).' GB',
            $bytes >= 1_048_576 => number_format($bytes / 1_048_576, 1).' MB',
            $bytes >= 1024 => number_format($bytes / 1024, 0).' KB',
            default => $bytes.' B',
        };
    }
}
