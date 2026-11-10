<?php

namespace App\Http\Controllers\Join;

use App\Http\Controllers\Controller;
use App\Livewire\AgencyRegistration\PeopleCsv;
use Illuminate\Http\Response;

/**
 * The CSV template of the agency enrollment's People screen: the columns and two sample rows.
 * Public like the wizard, and a 404 while enrollment is off.
 */
class PeopleTemplateController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(config('portal.registration.enabled'), 404);

        return response(PeopleCsv::template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="properx-agency-people.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
