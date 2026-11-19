<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\Staff\StaffAlert;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Notifications\Notification as NovaNotificationRecord;
use Laravel\Nova\URL;
use Throwable;

/**
 * Staff's bell in Nova: only what needs someone to act, each role its own. The Membership Coordinator
 * hears of members' work (a license document to review, a new member from properx.org, a license
 * fixed after "Needs attention", a request for a new invitation, a refused application under the
 * 24-month rule); the Executive Director of money and breakage (a failed payment, a membership that
 * ended, an integration that was refused). The Compliance Specialist gets none of these. A click
 * opens the record. The same record rings once while its notification is unread. Names, emails and
 * other personal data never go into the message: record numbers only.
 */
class StaffAlerts
{
    /** kind => [role, icon, type, action text] */
    public const KINDS = [
        'license_document_uploaded' => [Role::MembershipCoordinator, 'document-text', 'info', 'Review the document'],
        'member_intake' => [Role::MembershipCoordinator, 'user', 'info', 'Open the profile'],
        'license_fixed' => [Role::MembershipCoordinator, 'check-circle', 'info', 'Open the verification queue'],
        'invitation_requested' => [Role::MembershipCoordinator, 'mail', 'info', 'Open the profile'],
        'rejoin_blocked' => [Role::MembershipCoordinator, 'exclamation-circle', 'warning', 'Open the member'],
        'payment_failed' => [Role::ExecutiveDirector, 'exclamation-circle', 'warning', 'Open the record'],
        'membership_ended' => [Role::ExecutiveDirector, 'x-circle', 'warning', 'Open the record'],
        'integration_refused' => [Role::ExecutiveDirector, 'exclamation-circle', 'error', 'Open the audit log'],
    ];

    /**
     * Rings the bell of everyone in the kind's role, unless an unread one for this record is there.
     *
     * @param  string  $path  Nova path of the record, e.g. "/resources/agencies/3"
     */
    public static function raise(string $kind, Model|string $subject, string $message, string $path): void
    {
        [$role, $icon, $type, $action] = self::KINDS[$kind];
        $key = $kind.':'.($subject instanceof Model ? $subject->getMorphClass().':'.$subject->getKey() : $subject);

        try {
            $staff = User::role($role->value)->get()->reject(fn (User $user) => $user->isMember());

            foreach ($staff as $user) {
                $waiting = NovaNotificationRecord::query()
                    ->where('notifiable_type', $user->getMorphClass())
                    ->where('notifiable_id', $user->getKey())
                    ->whereNull('read_at')
                    ->where('data->ppx_key', $key)
                    ->exists();

                if (! $waiting) {
                    $user->notify(StaffAlert::make()
                        ->key($kind, $key)
                        ->message($message)
                        ->action($action, URL::make($path))
                        ->icon($icon)
                        ->type($type));
                }
            }
        } catch (Throwable $e) {
            // The bell never breaks the work that rang it.
            Log::warning('Staff alert could not be raised', ['kind' => $kind, 'error' => $e::class]);
        }
    }

    /** Where refused calls come from, as the bell and the audit log name them. */
    public const SOURCES = [
        'wordpress_intake' => 'a paid application from properx.org',
        'wordpress_cancellation' => 'a cancellation from properx.org',
        'wordpress_agency_start' => 'an agency enrollment started on properx.org',
        'stripe_webhook' => 'a Stripe webhook event',
    ];

    /**
     * An integration's call was refused (bad signature, or fields that do not validate): audited with
     * the source, the reason and field names only, and the Executive Director's bell rings once while unread.
     *
     * @param  list<string>  $fields
     */
    public static function integrationRefused(string $source, string $reason, array $fields = []): void
    {
        try {
            AuditLog::record('integration_refused', null, details: array_filter(['source' => $source, 'reason' => $reason, 'fields' => $fields ?: null]));
        } catch (Throwable $e) {
            Log::warning('Refused integration call could not be audited', ['source' => $source, 'error' => $e::class]);
        }

        self::raise('integration_refused', 'integration:'.$source, 'The portal refused '.(self::SOURCES[$source] ?? $source).' ('.($reason === 'signature' ? 'the signature did not match' : 'invalid fields: '.implode(', ', $fields)).'). Check the connection settings.', '/resources/audit-logs');
    }

    /**
     * The roles that hear of something (for the daily summary).
     *
     * @return list<string>
     */
    public static function kindsFor(User $user): array
    {
        return array_keys(array_filter(self::KINDS, fn (array $kind) => $user->hasRole($kind[0]->value)));
    }
}
