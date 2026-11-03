<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A processed Stripe webhook event (idempotency record).
 */
#[Fillable(['id', 'type', 'member_profile_id', 'stripe_customer_id', 'pending_status', 'pending_paid_through_at', 'event_created_at', 'outcome', 'amount', 'currency', 'plan_name'])]
#[WithoutIncrementing]
class StripeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'pending_paid_through_at' => 'datetime',
            'event_created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MemberProfile, $this>
     */
    public function memberProfile(): BelongsTo
    {
        return $this->belongsTo(MemberProfile::class);
    }
}
