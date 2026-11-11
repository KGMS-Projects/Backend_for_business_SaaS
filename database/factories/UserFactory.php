<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => Role::Employee,
            'manager_id' => null,
        ];
    }

    public function employee(?User $manager = null): static
    {
        return $this->state(fn () => [
            'role' => Role::Employee,
            'manager_id' => $manager?->id,
        ]);
    }

    public function manager(): static
    {
        return $this->state(fn () => ['role' => Role::Manager, 'manager_id' => null]);
    }

    public function operations(): static
    {
        return $this->state(fn () => ['role' => Role::Operations, 'manager_id' => null]);
    }
}
