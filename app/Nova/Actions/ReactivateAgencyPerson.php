<?php

namespace App\Nova\Actions;

use App\Enums\MemberStatus;

class ReactivateAgencyPerson extends SetAgencyPersonStatus
{
    public $name = 'Reactivate person';

    public $confirmText = 'Set this person active again? Their access follows the agency’s membership.';

    public $confirmButtonText = 'Reactivate';

    public function uriKey(): string
    {
        return 'reactivate-agency-person';
    }

    protected function status(): MemberStatus
    {
        return MemberStatus::Active;
    }
}
