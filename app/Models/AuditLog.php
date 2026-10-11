<?php

namespace App\Models;

use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
     * Audited actions (ТЗ, розділ 8).
     */
    public const ACTIONS = [
        'login',
        'logout',
        'password_changed',
        'mfa_enrolled',
        'profile_updated',
        'document_uploaded',
        'document_downloaded',
        'document_deleted',
        'role_changed',
    ];

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
