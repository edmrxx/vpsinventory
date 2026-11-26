<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditTrail;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the audit trail.
 *
 * Everything that records an action goes through here, so the shape of a row
 * is decided in one place. Call it straight:
 *
 *     app(AuditService::class)->log(AuditAction::View, 'Users', 'Opened ...');
 *
 * or let the Auditable trait call it for created, updated and deleted.
 *
 * A failure here must never break the request the user asked for, so every
 * write is wrapped and only written to the log file if it fails.
 */
class AuditService
{
    /** Values that must never reach the audit table. */
    protected const MASKED = [
        'password',
        'password_confirmation',
        'remember_token',
        'api_token',
        'secret',
    ];

    /** Attribute changes that are not worth a row of their own. */
    protected const NOISE = [
        'remember_token',
        // A person's own light or dark screen (phase 35.1): a preference,
        // not a change to anybody's records.
        'appearance',
        'created_at',
        'updated_at',
    ];

    /** Set to false inside a block that must not be recorded. */
    protected bool $enabled = true;

    /**
     * Write one row.
     *
     * @param  array<string, mixed>  $properties
     */
    public function log(
        AuditAction $action,
        string $module,
        string $description,
        ?Model $subject = null,
        array $properties = [],
        ?User $actor = null,
    ): ?AuditTrail {
        if (! $this->enabled) {
            return null;
        }

        try {
            $actor ??= Auth::user();

            return AuditTrail::create([
                'user_id' => $actor?->getKey(),
                'user_name' => $actor?->name,
                'username' => $actor?->username,
                'role' => $this->roleOf($actor),
                'module' => $module,
                'action' => $action->value,
                'description' => $description,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'properties' => $properties === [] ? null : $this->mask($properties),
                'ip_address' => request()->ip(),
                'browser' => $this->browser(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (Throwable $e) {
            // The user's own action already succeeded. Losing one audit row
            // is better than failing their request, so this is only noted.
            Log::error('Could not write the audit trail.', [
                'action' => $action->value,
                'module' => $module,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /* --------------------------------------------------------- Shortcuts */

    public function logLogin(User $user): ?AuditTrail
    {
        return $this->log(
            AuditAction::Login,
            'Authentication',
            "{$user->username} signed in.",
            $user,
            actor: $user,
        );
    }

    public function logLogout(User $user): ?AuditTrail
    {
        return $this->log(
            AuditAction::Logout,
            'Authentication',
            "{$user->username} signed out.",
            $user,
            actor: $user,
        );
    }

    /**
     * Someone opened a record.
     */
    public function logView(string $module, string $description, ?Model $subject = null): ?AuditTrail
    {
        return $this->log(AuditAction::View, $module, $description, $subject);
    }

    /**
     * An account or record was switched on or off.
     */
    public function logStatus(
        bool $isActive,
        string $module,
        string $description,
        ?Model $subject = null,
    ): ?AuditTrail {
        return $this->log(
            $isActive ? AuditAction::Activate : AuditAction::Deactivate,
            $module,
            $description,
            $subject,
        );
    }

    /* ------------------------------------------------------------ Control */

    /**
     * Run a block without recording anything.
     *
     * Used where one save would otherwise produce two rows, for example a
     * status toggle that is logged as Activate rather than Update.
     */
    public function withoutAuditing(callable $callback): mixed
    {
        $previous = $this->enabled;
        $this->enabled = false;

        try {
            return $callback();
        } finally {
            $this->enabled = $previous;
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /* ------------------------------------------------------------ Helpers */

    /**
     * The attribute changes worth recording, with secrets masked.
     *
     * @return array<string, mixed>
     */
    public function changesOf(Model $model): array
    {
        $changed = collect($model->getChanges())
            ->except(self::NOISE)
            ->keys();

        if ($changed->isEmpty()) {
            return [];
        }

        $original = $model->getRawOriginal();

        $changes = $changed->mapWithKeys(function (string $key) use ($model, $original) {
            if (in_array($key, self::MASKED, true)) {
                return [$key => ['from' => '••••••', 'to' => '••••••']];
            }

            return [$key => [
                'from' => $original[$key] ?? null,
                'to' => $model->getAttribute($key),
            ]];
        });

        return ['changed' => $changes->all()];
    }

    /**
     * The roles a user held at the moment of the action.
     */
    protected function roleOf(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $roles = $user->getRoleNames();

        return $roles->isEmpty() ? null : $roles->implode(', ');
    }

    /**
     * A short, readable browser name taken from the user agent.
     */
    protected function browser(): ?string
    {
        $agent = request()->userAgent();

        if (! $agent) {
            return null;
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Chrome/') && ! str_contains($agent, 'Chromium') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') && str_contains($agent, 'Version/') => 'Safari',
            str_contains($agent, 'curl/') => 'curl',
            str_contains($agent, 'Symfony') || str_contains($agent, 'PHPUnit') => 'Test client',
            default => 'Other',
        };

        $platform = match (true) {
            str_contains($agent, 'Windows NT 10') => 'Windows',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $platform ? "{$browser} on {$platform}" : $browser;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    protected function mask(array $properties): array
    {
        array_walk_recursive($properties, function (&$value, $key) {
            if (in_array((string) $key, self::MASKED, true)) {
                $value = '••••••';
            }
        });

        return $properties;
    }
}
