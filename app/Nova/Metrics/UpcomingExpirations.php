<?php

namespace App\Nova\Metrics;

use App\Models\MemberProfile;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Metrics\MetricTableRow;
use Laravel\Nova\Metrics\Table;

class UpcomingExpirations extends Table
{
    public $name = 'Upcoming License Expirations';

    public $width = '2/3';

    private const LIMIT = 10;

    /**
     * @return array<int, MetricTableRow>
     */
    public function calculate(NovaRequest $request): array
    {
        return MemberProfile::query()
            ->whereDate('license_expires_at', '>=', today())
            ->orderBy('license_expires_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(function (MemberProfile $profile) {
                $daysLeft = (int) today()->diffInDays($profile->license_expires_at);

                return MetricTableRow::make()
                    ->icon($daysLeft <= 7 ? 'exclamation-circle' : 'clock')
                    ->iconClass($daysLeft <= 30 ? 'text-red-500' : 'text-amber-500')
                    ->title($profile->fullName())
                    ->subtitle(sprintf(
                        '%s · %s · expires %s (%s)',
                        $profile->license_number ?? 'no license number',
                        $profile->license_state ?? '—',
                        $profile->license_expires_at->toFormattedDateString(),
                        trans_choice('{0} today|{1} in :count day|[2,*] in :count days', $daysLeft),
                    ))
                    ->actions(fn () => [
                        MenuItem::link('View profile', "/resources/member-profiles/{$profile->getKey()}"),
                    ]);
            })
            ->all();
    }

    public function uriKey(): string
    {
        return 'upcoming-expirations';
    }
}
