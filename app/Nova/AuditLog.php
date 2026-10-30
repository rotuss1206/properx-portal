<?php

namespace App\Nova;

use App\Enums\AgencyTier;
use App\Enums\RateType;
use App\Enums\Role;
use App\Models\User;
use App\Nova\Actions\ChangeAgencyPricing;
use App\Nova\Filters\AuditResourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;

/**
 * Read-only audit trail (see AuditLogPolicy). Rows hold IDs and field names only; names
 * of people and agencies are looked up from the linked records when shown.
 *
 * @extends \App\Nova\Resource<\App\Models\AuditLog>
 */
class AuditLog extends Resource
{
    public static $model = \App\Models\AuditLog::class;

    public static $title = 'id';

    public static $search = ['action', 'ip_address'];

    public static $group = 'Logs';

    public static $globallySearchable = false;

    public static $with = ['user', 'resource'];

    public static function label()
    {
        return 'Audit Log';
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            DateTime::make('Time', 'created_at')->sortable()->filterable(),

            // Plain text: the actor may be staff or a member (or deleted).
            Text::make('User', fn () => $this->resource->user
                ? "{$this->resource->user->name} <{$this->resource->user->email}>"
                : '—'),

            Select::make('Action')
                ->options(collect(\App\Models\AuditLog::ACTIONS)
                    ->mapWithKeys(fn (string $action) => [$action => Str::headline($action)])
                    ->all())
                ->displayUsingLabels()
                ->sortable()
                ->filterable(),

            Text::make('Resource', fn () => $this->resourceLink())->asHtml(),

            Text::make('Changes', fn () => $this->changes())->onlyOnDetail(),

            Text::make('IP Address'),

            Text::make('User Agent')->onlyOnDetail(),
        ];
    }

    public function filters(NovaRequest $request): array
    {
        return [new AuditResourceType];
    }

    /**
     * The record's current name, linked to it; "#ID (deleted)" once it is gone.
     */
    private function resourceLink(): string
    {
        $log = $this->resource;

        if ($log->resource_type === null) {
            return '—';
        }

        $label = array_search($log->resource_type, AuditResourceType::TYPES, true) ?: class_basename($log->resource_type);
        $model = $log->resource;

        if ($model === null) {
            return e("{$label} #{$log->resource_id} (deleted)");
        }

        $novaResource = self::novaResourceFor($model);

        if ($novaResource === null) {
            return e("{$label} #{$log->resource_id}");
        }

        $title = (new $novaResource($model))->title();
        $url = Nova::url("/resources/{$novaResource::uriKey()}/{$model->getKey()}");

        return '<a class="link-default" href="'.e($url).'">'.e("{$label}: {$title}").'</a>';
    }

    /**
     * Users have two Nova resources: Member for members, Staff for everyone else.
     *
     * @return class-string<\Laravel\Nova\Resource>|null
     */
    private static function novaResourceFor(Model $model): ?string
    {
        if ($model instanceof User) {
            return $model->hasRole(Role::Member->value) ? Member::class : Staff::class;
        }

        return Nova::resourceForModel($model);
    }

    /**
     * What changed, from the IDs, role and field names stored with the entry.
     */
    private function changes(): ?string
    {
        $details = $this->resource->details ?? [];

        return match ($this->resource->action) {
            'agency_principal_changed' => 'Principal: '.self::userLabel($details['from_user_id'] ?? null).' → '.self::userLabel($details['to_user_id'] ?? null),
            'role_changed' => implode('; ', array_filter([
                ($details['added'] ?? []) !== [] ? 'Role added: '.implode(', ', $details['added']) : null,
                ($details['removed'] ?? []) !== [] ? 'Role removed: '.implode(', ', $details['removed']) : null,
            ])) ?: null,
            'executive_director_assigned' => 'Via the console command'.(($details['account_created'] ?? false) ? '; account created' : ''),
            'executive_director_revoked' => 'Via the console command',
            'agency_updated', 'profile_updated' => isset($details['fields'])
                ? 'Changed fields: '.implode(', ', array_map(fn (string $field) => Str::headline($field), $details['fields']))
                : null,
            'agency_tier_changed' => self::fromTo('Tier', AgencyTier::tryFrom((string) ($details['from'] ?? ''))?->label(), AgencyTier::tryFrom((string) ($details['to'] ?? ''))?->label(), $details['reason'] ?? null),
            'agency_rate_type_changed' => self::fromTo('Rate type', RateType::tryFrom((string) ($details['from'] ?? ''))?->label(), RateType::tryFrom((string) ($details['to'] ?? ''))?->label(), $details['reason'] ?? null),
            default => null,
        };
    }

    private static function fromTo(string $what, ?string $from, ?string $to, ?string $reason): string
    {
        $reason = $reason === null ? null : (ChangeAgencyPricing::REASONS[$reason] ?? $reason);

        return "{$what}: ".($from ?? '—').' → '.($to ?? '—').($reason ? ". Reason: {$reason}" : '');
    }

    private static function userLabel(mixed $id): string
    {
        if ($id === null) {
            return '—';
        }

        $user = User::query()->find($id);

        return $user ? "{$user->name} (#{$id})" : "#{$id} (deleted)";
    }
}
