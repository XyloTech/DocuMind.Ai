<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * Accounts created by tests have already answered the consent prompt with
     * history storage on, because every chat test exercises retention. Real
     * accounts start unconsented (see `unconsented()`), so production always
     * captures an explicit choice first.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'credits' => 10,
            'role' => UserRole::User,
            'is_banned' => false,
            'store_chat_history' => true,
            'allow_model_training' => false,
            'privacy_consent_at' => now(),
            'privacy_consent_version' => (string) config('privacy.consent_version'),
        ];
    }

    /**
     * A brand new account that has not answered the consent prompt yet.
     */
    public function unconsented(): static
    {
        return $this->state(fn (array $attributes) => [
            'store_chat_history' => false,
            'allow_model_training' => false,
            'privacy_consent_at' => null,
            'privacy_consent_version' => null,
            'chat_retention_days' => null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
        ]);
    }

    public function support(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Support,
        ]);
    }

    public function analyst(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Analyst,
        ]);
    }

    public function banned(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_banned' => true,
        ]);
    }

    public function withCredits(int $credits): static
    {
        return $this->state(fn (array $attributes) => [
            'credits' => $credits,
        ]);
    }
}
