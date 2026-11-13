<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsWithinBudget;
use App\Models\MemberProfile;
use App\Services\Agencies\AgencyRosterChanges;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * People removed from an agency leave it on its renewal date (G4): off the agency, inactive, with
 * no paid membership of their own (they were offered individual membership when it was scheduled).
 * Hourly and idempotent; stops at the time budget.
 */
#[Signature('app:apply-agency-removals')]
#[Description('Take people off their agency once their removal date has come')]
class ApplyAgencyRemovals extends Command
{
    use RunsWithinBudget;

    public function handle(AgencyRosterChanges $roster): int
    {
        $this->startBudget();
        $applied = 0;

        $this->within(
            MemberProfile::query()->whereNotNull('agency_id')->whereNotNull('removal_effective_at')->where('removal_effective_at', '<=', now())->with('agency'),
            'Member profile',
            function (MemberProfile $person) use ($roster, &$applied) {
                $roster->applyRemoval($person);
                $applied++;
            },
        );

        $this->info("Removals applied: {$applied}, errors: ".count($this->errors).'.');

        return $this->finishBudget();
    }
}
