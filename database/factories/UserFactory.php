<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array{
     *     telegram_id: int,
     *     username: string,
     *     first_name: string,
     *     last_name: string,
     *     language_code: string,
     *     last_authenticated_at: Carbon,
     * }
     */
    public function definition(): array
    {
        return [
            'telegram_id' => fake()->unique()->numberBetween(100_000, 9_000_000_000),
            'username' => fake()->unique()->userName(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'language_code' => fake()->languageCode(),
            'last_authenticated_at' => now(),
        ];
    }
}
