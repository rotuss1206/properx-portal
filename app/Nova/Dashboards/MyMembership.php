<?php

namespace App\Nova\Dashboards;

use App\Nova\Metrics\MyLicense;
use Illuminate\Http\Request;
use Laravel\Nova\Card;
use Laravel\Nova\Dashboard;

/**
 * Member home page. Visibility is set in NovaServiceProvider::dashboards().
 */
class MyMembership extends Dashboard
{
    public function name()
    {
        return 'My Membership';
    }

    public function uriKey()
    {
        return 'my-membership';
    }

    /**
     * @return array<int, Card>
     */
    public function cards(): array
    {
        return [
            MyLicense::make()
                ->canSee(fn (Request $request) => (bool) $request->user()?->isMember())
                ->width('1/2'),
        ];
    }
}
