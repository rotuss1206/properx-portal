<?php

namespace App\Nova\Filters;

use App\Models\Agency;
use App\Models\LibraryDocument;
use App\Models\LicenseDocument;
use App\Models\MemberProfile;
use App\Models\PartnerCode;
use App\Models\TaxDocument;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Audit Log entries about one kind of record (Agency, User, ...).
 */
class AuditResourceType extends Filter
{
    public $name = 'Resource';

    /**
     * Model classes as stored in audit_logs.resource_type, by label.
     */
    public const TYPES = [
        'Agency' => Agency::class,
        'User' => User::class,
        'Member Profile' => MemberProfile::class,
        'Partner Code' => PartnerCode::class,
        'License Document' => LicenseDocument::class,
        'Tax Document' => TaxDocument::class,
        'Library Document' => LibraryDocument::class,
    ];

    public function apply(NovaRequest $request, Builder $query, mixed $value): Builder
    {
        return in_array($value, self::TYPES, true) ? $query->where('resource_type', $value) : $query;
    }

    public function options(NovaRequest $request): array
    {
        return self::TYPES;
    }
}
