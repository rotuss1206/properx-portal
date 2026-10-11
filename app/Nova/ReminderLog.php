<?php

namespace App\Nova;

use App\Enums\DeliveryStatus;
use App\Enums\ReminderType;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Read-only history of license expiry reminders (see ReminderLogPolicy).
 *
 * @extends \App\Nova\Resource<\App\Models\ReminderLog>
 */
class ReminderLog extends Resource
{
    public static $model = \App\Models\ReminderLog::class;

    public static $title = 'id';

    public static $search = ['id'];

    public static $group = 'Logs';

    public static $globallySearchable = false;

    public static function label()
    {
        return 'Reminder Log';
    }

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),

            BelongsTo::make('Member', 'user', Member::class)->sortable(),

            Select::make('Reminder', 'reminder_type')
                ->options(collect(ReminderType::cases())
                    ->mapWithKeys(fn (ReminderType $type) => [$type->value => "{$type->value} days before expiry"])
                    ->all())
                ->displayUsingLabels()
                ->sortable()
                ->filterable(),

            DateTime::make('Sent At')->sortable()->filterable(),

            Badge::make('Delivery Status')
                ->map([
                    DeliveryStatus::Sent->value => 'info',
                    DeliveryStatus::Delivered->value => 'success',
                    DeliveryStatus::Skipped->value => 'warning',
                    DeliveryStatus::Bounced->value => 'danger',
                    DeliveryStatus::Complained->value => 'danger',
                    DeliveryStatus::Failed->value => 'danger',
                ])
                ->labels(collect(DeliveryStatus::cases())
                    ->mapWithKeys(fn (DeliveryStatus $status) => [$status->value => Str::headline($status->value)])
                    ->all())
                ->filterable(),
        ];
    }
}
