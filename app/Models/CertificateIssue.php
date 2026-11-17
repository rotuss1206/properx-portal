<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When a member's certificate for a term was first issued (CertificateGeneratorService::issue).
 */
class CertificateIssue extends Model
{
    protected $fillable = ['member_profile_id', 'term_end', 'issued_on'];

    protected function casts(): array
    {
        return [
            'term_end' => 'date',
            'issued_on' => 'date',
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
