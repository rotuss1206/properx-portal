<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Append-only: rows are never updated, so there is no updated_at column.
 */
#[Fillable(['user_id', 'action', 'resource_type', 'resource_id', 'ip_address', 'user_agent'])]
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * Audited actions (Spec 8).
     */
    public const ACTIONS = [
        'login',
        'logout',
        'password_changed',
        'mfa_enrolled',
        'mfa_disabled',
        'profile_updated',
        'document_uploaded',
        'document_downloaded',
        'document_deleted',
        'document_verified',
        'document_rejected',
        'certificate_generated',
        'certificate_downloaded',
        'role_changed',
        'payment_status_changed',
        'stripe_event_processed',
        'api_key_rotated',
        'password_link_created',
        'agency_created',
        'agency_updated',
        'agency_deleted',
        'agency_principal_changed',
        'agency_member_added',
        'agency_member_removed',
        'partner_code_created',
        'partner_code_updated',
        'partner_code_deleted',
    ];

    /**
     * Append an entry for the current request (IP, user agent, signed-in user).
     */
    public static function record(string $action, ?Model $resource = null, ?User $user = null): self
    {
        $request = request();

        return static::create([
            'user_id' => ($user ?? $request->user() ?? auth()->user())?->getKey(),
            'action' => $action,
            'resource_type' => $resource?->getMorphClass(),
            'resource_id' => $resource?->getKey(),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function resource(): MorphTo
    {
        return $this->morphTo();
    }
}
