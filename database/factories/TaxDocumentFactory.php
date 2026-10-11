<?php

namespace Database\Factories;

use App\Models\TaxDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TaxDocument>
 */
class TaxDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(TaxDocument::DOCUMENT_TYPES);
        $year = fake()->numberBetween(now()->year - 3, now()->year - 1);
        $uploadedAt = fake()->dateTimeBetween('-1 year');

        return [
            'user_id' => User::factory(),
            'document_type' => $type,
            'tax_year' => $year,
            'stored_filename' => Str::uuid().'.pdf',
            'original_filename' => Str::slug($type)."-{$year}.pdf",
            'uploaded_at' => $uploadedAt,
            'downloaded_at' => fake()->optional(0.5)->dateTimeBetween($uploadedAt),
        ];
    }
}
