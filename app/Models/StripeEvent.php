<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A processed Stripe webhook event (idempotency record).
 */
#[Fillable(['id', 'type', 'member_profile_id', 'outcome'])]
#[WithoutIncrementing]
class StripeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $keyType = 'string';

    /**
     * @return BelongsTo<MemberProfile, $this>
     */
    public function memberProfile(): BelongsTo
    {
        return $this->belongsTo(MemberProfile::class);
    }
}
