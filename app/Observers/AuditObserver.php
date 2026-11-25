<?php

namespace App\Observers;

use App\Enums\AuditAction;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;

/**
 * Records create, update and delete for any model using the Auditable trait.
 *
 * The trait registers this observer, so a future module needs no code of its
 * own beyond `use Auditable;`.
 */
class AuditObserver
{
    public function __construct(protected AuditService $audit) {}

    public function created(Model $model): void
    {
        $properties = $model->auditNewValues();

        if ($model->auditExtra !== []) {
            $properties['new'] = array_merge($properties['new'] ?? [], $model->auditExtra);
        }

        $this->audit->log(
            AuditAction::Create,
            $model->auditModule(),
            "Created {$model->auditName()} [{$model->auditLabel()}].",
            $model,
            $properties,
        );

        $model->auditExtra = [];
    }

    public function updated(Model $model): void
    {
        $changed = array_merge(
            $this->audit->changesOf($model)['changed'] ?? [],
            $model->auditExtra,
        );

        $model->auditExtra = [];

        // Nothing of interest changed, for example only the remember token
        // after a login. No row is written.
        if ($changed === []) {
            return;
        }

        $this->audit->log(
            AuditAction::Update,
            $model->auditModule(),
            "Updated {$model->auditName()} [{$model->auditLabel()}]: "
                .implode(', ', array_keys($changed)).'.',
            $model,
            ['changed' => $changed],
        );
    }

    public function deleted(Model $model): void
    {
        $this->audit->log(
            AuditAction::Delete,
            $model->auditModule(),
            "Deleted {$model->auditName()} [{$model->auditLabel()}].",
            $model,
        );
    }
}
