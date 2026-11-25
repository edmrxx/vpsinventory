<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\BackupMaker;
use App\Support\SettingsSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

/**
 * The Backups screen (phase 35): make one now, download one, delete one.
 *
 * Every route carries its own `backups.*` permission — Admin only — because
 * a backup is the whole books and every password hash. A backup is named
 * only by `BackupMaker::NAME`; anything else, `../.env` included, is a 404.
 * Restoring is never done from here: see RESTORE.txt in every backup.
 */
class BackupController extends Controller
{
    public function __construct(
        protected BackupMaker $maker,
        protected AuditService $audit,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $frequency = (string) settings('backup.frequency', 'off');

        return Inertia::render('Backups/Index', [
            'title' => 'Backups',
            'backups' => $this->maker->all(),
            'settings' => [
                'frequency' => $frequency,
                'frequency_label' => SettingsSchema::field('backup.frequency')['options'][$frequency] ?? $frequency,
                'keep_copies' => (int) settings('backup.keep_copies', 7),
                'include_files' => (bool) settings('backup.include_files', true),
            ],
            'available' => class_exists(ZipArchive::class),
            'can' => [
                'create' => $user->can('backups.create'),
                'download' => $user->can('backups.download'),
                'delete' => $user->can('backups.delete'),
                'settings' => $user->can('settings.view'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        @set_time_limit(300);

        try {
            $backup = $this->maker->make($request->user());
        } catch (Throwable $failed) {
            report($failed);

            return back()->with('error', 'The backup could not be made: '.$failed->getMessage());
        }

        return back()->with('success', "Backup made: {$backup['name']} (".BackupMaker::readableSize($backup['size']).'). Download a copy and keep it off the server.');
    }

    public function download(string $name): BinaryFileResponse
    {
        $path = $this->maker->path($name) ?? abort(404);

        $this->audit->logView('Backups', "Downloaded backup [{$name}].");

        return response()->download($path, $name, ['Content-Type' => 'application/zip']);
    }

    public function destroy(Request $request, string $name): RedirectResponse
    {
        abort_unless($this->maker->delete($name, $request->user()), 404);

        return back()->with('success', "Backup {$name} deleted.");
    }
}
