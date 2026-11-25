<?php

namespace App\Concerns;

use App\Enums\AuditAction;
use App\Models\AuditTrail;
use App\Observers\AuditObserver;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * Makes a model write its own audit trail.
 *
 * A future module needs one line:
 *
 *     class Member extends Model
 *     {
 *         use Auditable;
 *     }
 *
 * Create, update and delete are then recorded by themselves. Override the
 * three small methods below to change the wording, and call `auditView()` or
 * `auditStatus()` from the controller for the actions Eloquent cannot see.
 */
trait Auditable
{
    /**
     * Changes to fold into the next audit row that Eloquent cannot see by
     * itself, such as roles or permissions kept in a pivot table.
     *
     * Set it before saving: `$user->auditExtra = ['roles' => [...]];`
     *
     * @var array<string, mixed>
     */
    public array $auditExtra = [];

    /**
     * Called once per model class, so the observer registers itself.
     */
    public static function bootAuditable(): void
    {
        static::observe(AuditObserver::class);
    }

    /**
     * The module name shown in the trail. Defaults to the plural class name,
     * for example "Users" for the User model.
     */
    public function auditModule(): string
    {
        return Str::plural(Str::headline(class_basename($this)));
    }

    /**
     * The singular word used in a description, for example "user".
     */
    public function auditName(): string
    {
        return Str::lower(Str::headline(class_basename($this)));
    }

    /**
     * How one record is named in the trail. Defaults to its name, then its
     * title, then its key.
     */
    public function auditLabel(): string
    {
        return (string) ($this->name ?? $this->title ?? $this->getKey());
    }

    /**
     * The values recorded when the record is created. Hidden attributes such
     * as the password are left out by `attributesToArray()`.
     *
     * @return array<string, mixed>
     */
    public function auditNewValues(): array
    {
        $values = collect($this->attributesToArray())
            ->except(['id', 'created_at', 'updated_at'])
            ->all();

        return $values === [] ? [] : ['new' => $values];
    }

    /* ----------------------------------------------- Actions with no event */

    /**
     * Record that someone opened this record.
     */
    public function auditView(?string $description = null): ?AuditTrail
    {
        return app(AuditService::class)->log(
            AuditAction::View,
            $this->auditModule(),
            $description ?? "Opened {$this->auditName()} [{$this->auditLabel()}].",
            $this,
        );
    }

    /**
     * Record that someone switched this record on or off.
     */
    public function auditStatus(bool $isActive, ?string $description = null): ?AuditTrail
    {
        $word = $isActive ? 'Activated' : 'Deactivated';

        return app(AuditService::class)->logStatus(
            $isActive,
            $this->auditModule(),
            $description ?? "{$word} {$this->auditName()} [{$this->auditLabel()}].",
            $this,
        );
    }

    /**
     * Record an edit where only related rows changed, so Eloquent fired no
     * `updated` event. Call it after syncing the relation.
     *
     * @param  array<string, mixed>  $changed
     */
    public function auditRelationChange(array $changed): ?AuditTrail
    {
        if ($changed === []) {
            return null;
        }

        return app(AuditService::class)->log(
            AuditAction::Update,
            $this->auditModule(),
            "Updated {$this->auditName()} [{$this->auditLabel()}]: "
                .implode(', ', array_keys($changed)).'.',
            $this,
            ['changed' => $changed],
        );
    }

    /**
     * Record something this module alone cares about.
     *
     * @param  array<string, mixed>  $properties
     */
    public function auditCustom(
        AuditAction $action,
        string $description,
        array $properties = [],
    ): ?AuditTrail {
        return app(AuditService::class)->log(
            $action,
            $this->auditModule(),
            $description,
            $this,
            $properties,
        );
    }

    /**
     * Every trail row that points at this record.
     */
    public function auditTrails(): MorphMany
    {
        return $this->morphMany(AuditTrail::class, 'subject')->latest('created_at');
    }
}
