<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\ReminderType;
use Database\Factories\ReminderLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reminder_type', 'sent_at', 'delivery_status'])]
class ReminderLog extends Model
{
    /** @use HasFactory<ReminderLogFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'reminder_type' => ReminderType::class,
            'sent_at' => 'datetime',
            'delivery_status' => DeliveryStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
