<?php

namespace Database\Factories;

use App\Enums\VerificationStatus;
use App\Models\LicenseDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LicenseDocument>
 */
class LicenseDocumentFactory extends Factory
{
    private const FILE_TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $extension = fake()->randomElement(array_keys(self::FILE_TYPES));
        $status = fake()->randomElement(VerificationStatus::cases());

        return [
            'user_id' => User::factory(),
            'stored_filename' => LicenseDocument::DIRECTORY.'/'.Str::uuid().'.'.$extension,
            'original_filename' => 'license-'.fake()->word().'.'.$extension,
            'mime_type' => self::FILE_TYPES[$extension],
            'size' => fake()->numberBetween(50 * 1024, 10 * 1024 * 1024),
            'uploaded_at' => fake()->dateTimeBetween('-1 year'),
            'verification_status' => $status,
            'admin_notes' => $status === VerificationStatus::Rejected ? 'Document is unreadable, please re-upload.' : null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'verification_status' => VerificationStatus::Pending,
            'admin_notes' => null,
        ]);
    }
}
