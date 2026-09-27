<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentChunk>
 */
class DocumentChunkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = fake()->paragraph();

        return [
            'document_id' => Document::factory(),
            'chunk_index' => $this->unique()->numberBetween(0, 100000),
            'chunk_text' => $text,
            'embedding' => [0.0],
            'embedding_norm' => null,
            'page_from' => 1,
            'page_to' => 1,
            'char_start' => 0,
            'char_end' => mb_strlen($text),
            'token_estimate' => (int) ceil(mb_strlen($text) / 4),
            'content_hash' => sha1($text),
        ];
    }
}
