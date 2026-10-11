<?php

namespace App\Listeners;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;

/**
 * Audits role assignments and removals (Spec §8). syncRoles() fires a detach and an
 * attach event for one change, so each user is recorded once per request.
 */
class RecordRoleChange
{
    public function handleAttached(RoleAttachedEvent $event): void
    {
        $this->record($event->model);
    }

    public function handleDetached(RoleDetachedEvent $event): void
    {
        $this->record($event->model);
    }

    private function record(Model $model): void
    {
        $key = 'audit.role_changed.'.$model->getMorphClass().'.'.$model->getKey();
        $attributes = request()->attributes;

        if ($attributes->has($key)) {
            return;
        }

        $attributes->set($key, true);

        AuditLog::record('role_changed', $model);
    }
}
