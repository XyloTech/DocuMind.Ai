<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
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
            'filename' => fake()->words(3, true).'.pdf',
            'file_path' => 'documents/'.fake()->numberBetween(1, 99).'/'.fake()->uuid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(50_000, 5_000_000),
            'status' => DocumentStatus::Processed,
            'progress' => 100,
            'page_count' => fake()->numberBetween(1, 240),
            'chunk_count' => fake()->numberBetween(1, 600),
            'doc_hash' => hash('sha256', fake()->uuid()),
            'processed_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Pending,
            'progress' => 0,
            'page_count' => null,
            'chunk_count' => 0,
            'processed_at' => null,
        ]);
    }

    public function processing(int $progress = 50): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Processing,
            'progress' => $progress,
            'processed_at' => null,
        ]);
    }

    public function failed(string $message = 'The PDF could not be read.'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Failed,
            'progress' => 0,
            'error_message' => $message,
            'processed_at' => null,
        ]);
    }
}
