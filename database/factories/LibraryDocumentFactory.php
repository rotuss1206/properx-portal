<?php

namespace Database\Factories;

use App\Models\LibraryDocument;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LibraryDocument>
 */
class LibraryDocumentFactory extends Factory
{
    public const CATEGORIES = [
        'Compliance Briefings',
        'Training',
        'Regulatory Updates',
        'Forms & Templates',
    ];

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = rtrim(fake()->sentence(4), '.');

        return [
            'title' => $title,
            'description' => fake()->paragraph(),
            'category' => fake()->randomElement(self::CATEGORIES),
            'file_path' => 'library/'.Str::slug($title).'-'.Str::random(6).'.pdf',
            'published' => fake()->boolean(80),
            'uploaded_at' => fake()->dateTimeBetween('-1 year'),
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['published' => true]);
    }

    public function draft(): static
    {
        return $this->state(fn () => ['published' => false]);
    }
}
