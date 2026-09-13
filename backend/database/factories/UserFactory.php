<?php

namespace Database\Factories;

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
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('+2771#######'),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => User::ROLE_PATIENT,
            'status' => 'active',
            'remember_token' => Str::random(10),
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_SUPER_ADMIN]);
    }

    public function clinicAdmin(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_CLINIC_ADMIN]);
    }

    public function doctor(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_DOCTOR]);
    }

    public function driver(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_DRIVER]);
    }

    public function patient(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_PATIENT]);
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
}
