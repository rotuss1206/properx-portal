<?php

namespace App\Services\Maintenance;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What app:purge-test-data would remove (TestDataPurge::plan). Read-only until purge() runs.
 */
final readonly class TestDataPurgePlan
{
    /**
     * @param  Collection<int, User>  $users  the accounts to remove
     * @param  list<string>  $notFound  listed emails without an account
     * @param  Collection<int, User>  $skippedStaff  staff matched by a domain only (kept)
     * @param  Collection<int, Agency>  $keptAgencies  agencies of a selected principal that keep other people (kept)
     * @param  ?string  $problem  why the purge must not run, if anything
     * @param  array{users: list<int>, profiles: list<int>, agencies: list<int>, drafts: list<int>, customers: list<string>}  $ids
     * @param  array<string, int>  $counts  what goes, by kind
     */
    public function __construct(
        public Collection $users,
        public array $notFound,
        public Collection $skippedStaff,
        public Collection $keptAgencies,
        public ?string $problem,
        public array $ids,
        public array $counts,
    ) {}

    public function isEmpty(): bool
    {
        return $this->users->isEmpty() && $this->ids['drafts'] === [];
    }
}
