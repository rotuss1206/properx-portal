<?php

namespace App\Nova;

use App\Enums\AgencyStatus;
use App\Enums\AgencyTier;
use App\Enums\LicenseVerification;
use App\Enums\RateType;
use App\Enums\Role;
use App\Models\User;
use App\Nova\Actions\CancelAgencyMembership;
use App\Nova\Actions\ChangeAgencyPricing;
use App\Nova\Actions\DeactivateAgencyPerson;
use App\Nova\Filters\AuditResourceType;
use App\Services\Agencies\AgencyRosterChanges;
use App\Support\Money;
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
            'agency_payment_status_changed' => self::fromTo('Payment status', AgencyStatus::tryFrom((string) ($details['from'] ?? ''))?->label(), AgencyStatus::tryFrom((string) ($details['to'] ?? ''))?->label(), null),
            'agency_payment_failed' => 'Stripe reported a failed payment'.(isset($details['invoice']) ? ' (invoice '.$details['invoice'].')' : ''),
            'agency_price_changed_in_stripe' => 'Price changed in Stripe: '
                .implode(' ', array_filter([AgencyTier::tryFrom((string) ($details['from'][0] ?? ''))?->label(), RateType::tryFrom((string) ($details['from'][1] ?? ''))?->label()]))
                .' → '.implode(' ', array_filter([AgencyTier::tryFrom((string) ($details['to'][0] ?? ''))?->label(), RateType::tryFrom((string) ($details['to'][1] ?? ''))?->label()])),
            'agency_enrolled' => implode('; ', array_filter([
                'Online enrollment: '.(AgencyTier::tryFrom((string) ($details['tier'] ?? ''))?->label() ?? '—').', '.($details['headcount'] ?? '—').' people',
                isset($details['amount']) ? 'dues '.Money::format((int) $details['amount']).(($details['surcharge'] ?? 0) > 0 ? ' + surcharge '.Money::format((int) $details['surcharge']) : '') : null,
                isset($details['payment_method']) ? 'paid by '.Str::headline((string) $details['payment_method']) : null,
                ($details['people_not_created'] ?? 0) > 0 ? $details['people_not_created'].' people not created (email or NPN already in the portal)' : null,
                isset($details['consents_given_at']) ? 'consents v'.($details['consents_version'] ?? '?').' given at '.implode(', ', $details['consents_given_at']) : null,
            ])),
            'agency_invitation_sent' => match ($details['kind'] ?? null) {
                'resend' => 'New invitation sent by staff'.(($details['revoked'] ?? 0) > 0 ? '; earlier link cancelled' : ''),
                'reminder' => 'Invitation reminder, day '.($details['day'] ?? '?'),
                default => 'Invitation sent after the enrollment'.(($details['principal'] ?? false) ? ' (welcome and receipt)' : ''),
            },
            'agency_invitation_password_set' => 'Password set through the invitation link',
            'agency_invitation_accepted' => 'Profile checked; consents v'.($details['consents_version'] ?? '?').': '.implode(', ', array_map(fn (string $key) => Str::headline($key), $details['consents'] ?? [])),
            'agency_invitation_requested' => 'Asked for a new invitation from a '.($details['state'] ?? '—').' link',
            'license_verification_changed' => 'License verification: '.(LicenseVerification::tryFrom((string) ($details['from'] ?? ''))?->label() ?? '—')
                .' → '.(LicenseVerification::tryFrom((string) ($details['to'] ?? ''))?->label() ?? '—')
                .(($details['reason_given'] ?? false) ? ' (reason given to the person)' : ''),
            'billing_portal_opened' => 'Opened Stripe’s payment page for the '.(($details['kind'] ?? '') === 'agency' ? 'agency' : 'member').'’s billing',
            'agency_member_deactivated' => 'Person set inactive on the agency'.(isset($details['reason']) ? ': '.(DeactivateAgencyPerson::REASONS[$details['reason']] ?? $details['reason']) : ''),
            'agency_member_reactivated' => 'Person set active again on the agency',
            'agency_people_added' => ($details['people'] ?? 0).' people added; paid '.Money::format((int) ($details['amount'] ?? 0)).(($details['surcharge'] ?? 0) > 0 ? ' + surcharge '.Money::format((int) $details['surcharge']) : '').(isset($details['tier_to']) ? '; tier '.($details['tier_from'] ?? '?').' → '.$details['tier_to'] : ''),
            'agency_member_removal_scheduled' => 'Removal from the agency on '.($details['effective_on'] ?? '?').': '.(AgencyRosterChanges::REASONS[$details['reason'] ?? ''] ?? ($details['reason'] ?? '—')),
            'agency_member_removal_canceled' => 'Scheduled removal from the agency canceled',
            'agency_membership_canceled' => 'Membership canceled '.(($details['timing'] ?? '') === 'now' ? 'now' : 'at the end of the paid period')
                .(isset($details['reason']) ? ': '.(CancelAgencyMembership::REASONS[$details['reason']] ?? $details['reason']) : ''),
            'agency_updated', 'profile_updated' => isset($details['fields'])
                ? 'Changed fields: '.implode(', ', array_map(fn (string $field) => Str::headline($field), $details['fields']))
                : null,
            'notification_settings_updated' => implode('; ', array_filter([
                ($details['enabled'] ?? []) !== [] ? 'Turned on: '.implode(', ', array_map(fn (string $category) => Str::headline($category), $details['enabled'])) : null,
                ($details['disabled'] ?? []) !== [] ? 'Turned off: '.implode(', ', array_map(fn (string $category) => Str::headline($category), $details['disabled'])) : null,
            ])) ?: null,
            'certificate_generated', 'certificate_downloaded' => isset($details['format'])
                ? strtoupper((string) $details['format']).', term '.($details['term'] ?? '—').': member ID '.(($details['member_id'] ?? false) ? 'on' : 'off').', join date '.(($details['join_date'] ?? false) ? 'on' : 'off').', QR '.(($details['qr'] ?? false) ? 'on' : 'off')
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
