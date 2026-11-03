<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paid application received from the marketing site (idempotency record: source + entry id).
 * Holds ids, the outcome and field names only.
 */
#[Fillable(['source', 'entry_id', 'member_profile_id', 'outcome', 'result'])]
class IntakeSubmission extends Model
{
    protected function casts(): array
    {
        return ['result' => 'array'];
    }

    /**
     * @return BelongsTo<MemberProfile, $this>
     */
    public function memberProfile(): BelongsTo
    {
        return $this->belongsTo(MemberProfile::class);
    }
}
