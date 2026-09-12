<?php

namespace App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models;

use App\Models\Exercise;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $training_program_id
 * @property int $exercise_id
 * @property int $position
 * @property-read Exercise $exercise
 * @property-read Collection<int, PlannedSetModel> $plannedSets
 */
#[Fillable([
    'exercise_id',
    'position',
])]
final class PlannedExerciseModel extends Model
{
    protected $table = 'planned_exercises';

    /** @return BelongsTo<TrainingProgramModel, $this> */
    public function trainingProgram(): BelongsTo
    {
        return $this->belongsTo(TrainingProgramModel::class, 'training_program_id');
    }

    /** @return BelongsTo<Exercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /** @return HasMany<PlannedSetModel, $this> */
    public function plannedSets(): HasMany
    {
        return $this->hasMany(PlannedSetModel::class, 'planned_exercise_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'training_program_id' => 'integer',
            'exercise_id' => 'integer',
            'position' => 'integer',
        ];
    }
}
