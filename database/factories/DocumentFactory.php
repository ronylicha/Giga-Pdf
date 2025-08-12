<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Document>
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
        $extensions = ['pdf', 'docx', 'xlsx', 'pptx', 'txt', 'jpg', 'png'];
        $extension = fake()->randomElement($extensions);
        $originalName = fake()->word() . '.' . $extension;
        
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'original_name' => $originalName,
            'stored_name' => Str::uuid() . '.' . $extension,
            'mime_type' => $this->getMimeType($extension),
            'size' => fake()->numberBetween(1000, 10000000), // 1KB to 10MB
            'hash' => hash('sha256', fake()->uuid()),
            'extension' => $extension,
            'metadata' => [
                'pages' => fake()->numberBetween(1, 100),
                'author' => fake()->name(),
                'created_date' => fake()->dateTime()->format('Y-m-d H:i:s'),
            ],
            'is_public' => fake()->boolean(20), // 20% chance of being public
            'search_content' => fake()->optional()->paragraphs(3, true),
            'thumbnail_path' => fake()->optional()->uuid() . '.jpg',
        ];
    }

    /**
     * Get MIME type for extension.
     */
    private function getMimeType(string $extension): string
    {
        return match ($extension) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
    }

    /**
     * Indicate that the document is public.
     */
    public function public(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_public' => true,
        ]);
    }

    /**
     * Indicate that the document is a PDF.
     */
    public function pdf(): static
    {
        return $this->state(fn (array $attributes) => [
            'original_name' => fake()->word() . '.pdf',
            'stored_name' => Str::uuid() . '.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
        ]);
    }
}