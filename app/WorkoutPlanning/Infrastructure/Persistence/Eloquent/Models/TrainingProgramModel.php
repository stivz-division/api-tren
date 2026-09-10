<?php

namespace App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $weekday
 * @property string $name
 * @property-read Collection<int, PlannedExerciseModel> $plannedExercises
 */
#[Fillable([
    'user_id',
    'weekday',
    'name',
])]
final class TrainingProgramModel extends Model
{
    protected $table = 'training_programs';

    /** @return HasMany<PlannedExerciseModel, $this> */
    public function plannedExercises(): HasMany
    {
        return $this->hasMany(PlannedExerciseModel::class, 'training_program_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'user_id' => 'integer',
            'weekday' => 'integer',
        ];
    }
}
