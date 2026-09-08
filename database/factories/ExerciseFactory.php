<?php

namespace Database\Factories;

use App\Models\Discipline;
use App\Models\Exercise;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exercise>
 */
class ExerciseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array{discipline_id: DisciplineFactory, code: string, name: string}
     */
    public function definition(): array
    {
        return [
            'discipline_id' => Discipline::factory(),
            'code' => fake()->unique()->slug(3),
            'name' => fake()->word().' '.fake()->word().' '.fake()->word(),
        ];
    }
}
