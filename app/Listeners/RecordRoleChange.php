<?php

namespace App\Listeners;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\PermissionRegistrar;

/**
 * Audits role assignments and removals (Spec 8) with the role names added and removed.
 * syncRoles() fires a detach and an attach event for one change, so each user gets one
 * entry per request: later events of the same request update that entry's net change
 * (the only time an audit row is written twice, before the request ends).
 */
class RecordRoleChange
{
    public function handleAttached(RoleAttachedEvent $event): void
    {
        $this->record($event->model, added: $this->roleNames($event->rolesOrIds));
    }

    public function handleDetached(RoleDetachedEvent $event): void
    {
        $this->record($event->model, removed: $this->roleNames($event->rolesOrIds));
    }

    /**
     * @param  list<string>  $added
     * @param  list<string>  $removed
     */
    private function record(Model $model, array $added = [], array $removed = []): void
    {
        $key = 'audit.role_changed.'.$model->getMorphClass().'.'.$model->getKey();
        $attributes = request()->attributes;

        /** @var AuditLog|null $entry */
        $entry = $attributes->get($key);

        if ($entry === null) {
            $attributes->set($key, AuditLog::record('role_changed', $model, details: self::details($added, $removed)));

            return;
        }

        $details = $entry->details ?? [];
        $allAdded = [...($details['added'] ?? []), ...$added];
        $allRemoved = [...($details['removed'] ?? []), ...$removed];

        // A role removed and added back in the same request did not change.
        $entry->forceFill(['details' => self::details(
            array_diff($allAdded, $allRemoved),
            array_diff($allRemoved, $allAdded),
        )])->save();
    }

    /**
     * @param  array<int, string>  $added
     * @param  array<int, string>  $removed
     * @return array{added: list<string>, removed: list<string>}
     */
    private static function details(array $added, array $removed): array
    {
        return ['added' => array_values(array_unique($added)), 'removed' => array_values(array_unique($removed))];
    }

    /**
     * spatie passes role ids from HasRoles, but may pass models or a collection.
     *
     * @return list<string>
     */
    private function roleNames(mixed $rolesOrIds): array
    {
        $items = Collection::wrap($rolesOrIds instanceof Collection ? $rolesOrIds->all() : $rolesOrIds);

        $ids = $items->map(fn ($role) => $role instanceof Model ? $role->getKey() : $role)->filter()->all();

        return app(PermissionRegistrar::class)->getRoleClass()::query()->whereKey($ids)->orderBy('name')->pluck('name')->all();
    }
}
