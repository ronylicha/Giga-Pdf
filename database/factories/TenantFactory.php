<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(6),
            'domain' => fake()->optional()->domainName(),
            'settings' => [
                'theme' => 'default',
                'locale' => 'en',
                'timezone' => 'UTC',
            ],
            'max_storage_gb' => fake()->numberBetween(10, 100),
            'max_users' => fake()->numberBetween(5, 50),
            'max_file_size_mb' => fake()->numberBetween(10, 100),
            'features' => [
                'ocr' => true,
                'advanced_editing' => true,
                'api_access' => false,
            ],
            'subscription_plan' => fake()->randomElement(['basic', 'professional', 'enterprise']),
            'subscription_expires_at' => fake()->optional()->dateTimeBetween('now', '+1 year'),
            'is_suspended' => false,
        ];
    }

    /**
     * Indicate that the tenant is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_suspended' => true,
            'suspended_at' => now(),
            'suspension_reason' => 'Payment failed',
        ]);
    }

    /**
     * Indicate that the tenant has an enterprise plan.
     */
    public function enterprise(): static
    {
        return $this->state(fn (array $attributes) => [
            'subscription_plan' => 'enterprise',
            'max_storage_gb' => 1000,
            'max_users' => 500,
            'max_file_size_mb' => 500,
            'features' => [
                'ocr' => true,
                'advanced_editing' => true,
                'api_access' => true,
                'white_label' => true,
                'priority_support' => true,
            ],
        ]);
    }
}
