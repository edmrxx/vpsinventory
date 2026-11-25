<?php

namespace App\Services;

use App\Models\AuditTrail;
use Illuminate\Support\Facades\Cache;

/**
 * The activity feed on the dashboard.
 *
 * This once held the dashboard's figures as well, counted off the audit
 * trail: users, roles, actions today. Phase 12 replaced those with the real
 * inventory figures in `InventoryMetrics`, because a business dashboard
 * should answer "what did we sell" before "who clicked what".
 *
 * What is left is the one question the audit trail answers better than the
 * ledger does: who did what, and when.
 */
class DashboardMetrics
{
    /** How long the feed stays cached, in seconds. */
    public const TTL = 300;

    protected const PREFIX = 'dashboard.';

    /** The keys this service owns, so flushing needs no guesswork. */
    protected const KEYS = ['activity_feed'];

    /**
     * The newest rows of the audit trail, for the activity feed.
     *
     * The user is eager loaded in one extra query, so a list of ten rows
     * never turns into eleven queries.
     *
     * @return list<array<string, mixed>>
     */
    public function recentActivity(int $limit = 8): array
    {
        return $this->remember('activity_feed', fn () => AuditTrail::query()
            ->with('user:id,name,username')
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditTrail $trail) => [
                'id' => $trail->id,
                'description' => $trail->description,
                'module' => $trail->module,
                'action' => $trail->action->value,
                'action_label' => $trail->action->label(),
                'action_tone' => $trail->action->tone(),
                'who' => $trail->user?->name ?? $trail->user_name ?? 'System',
                'username' => $trail->user?->username ?? $trail->username,
                'initials' => $trail->user?->initials() ?? '··',
                'when' => $trail->created_at?->diffForHumans(),
                'at' => $trail->created_at?->format('d M Y, g:i A'),
            ])
            ->all());
    }

    /**
     * Clear the cached feed. Safe to call when nothing is cached.
     */
    public function flush(): void
    {
        foreach (self::KEYS as $key) {
            Cache::forget(self::PREFIX.$key);
        }
    }

    protected function remember(string $key, callable $callback): mixed
    {
        return Cache::remember(self::PREFIX.$key, self::TTL, $callback);
    }
}
