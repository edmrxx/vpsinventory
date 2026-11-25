<?php

namespace App\Console\Commands;

use App\Services\BackupMaker;
use Illuminate\Console\Command;
use Throwable;

/**
 * Make a backup from the command line or the scheduler (phase 35).
 *
 * The same backup as the Backups screen's button, with no web request's time
 * limit around it — which is why the scheduler calls this rather than the
 * page. Settings → Backup and Export decides how often (`routes/console.php`
 * reads it), and the server needs one cron job running `schedule:run`.
 */
class MakeBackup extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Back up the database, and the product photos and logo, to storage/app/private/backups';

    public function handle(BackupMaker $maker): int
    {
        @set_time_limit(0);

        try {
            $backup = $maker->make();
        } catch (Throwable $failed) {
            $this->error('The backup failed: '.$failed->getMessage());
            report($failed);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Made %s: %d tables, %s rows, %d file(s), %s.',
            $backup['name'],
            $backup['tables'],
            number_format($backup['rows']),
            $backup['files'],
            BackupMaker::readableSize($backup['size']),
        ));

        return self::SUCCESS;
    }
}
