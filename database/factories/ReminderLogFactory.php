<?php

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Enums\ReminderType;
use App\Models\ReminderLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReminderLog>
 */
class ReminderLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'reminder_type' => fake()->randomElement(ReminderType::cases()),
            'license_expires_at' => fake()->dateTimeBetween('today', '+60 days'),
            'sent_at' => fake()->dateTimeBetween('-60 days'),
            'delivery_status' => fake()->randomElement([
                DeliveryStatus::Delivered,
                DeliveryStatus::Delivered,
                DeliveryStatus::Sent,
                DeliveryStatus::Skipped,
                DeliveryStatus::Bounced,
            ]),
        ];
    }
}
