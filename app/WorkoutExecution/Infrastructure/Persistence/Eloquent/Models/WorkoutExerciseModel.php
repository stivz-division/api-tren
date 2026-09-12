<?php

namespace App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workout_session_id
 * @property int $exercise_id
 * @property string $exercise_name
 * @property int $position
 * @property string $status
 * @property-read Collection<int, WorkoutPlannedSetModel> $plannedSets
 * @property-read Collection<int, WorkoutSetModel> $workoutSets
 */
#[Fillable([
    'exercise_id',
    'exercise_name',
    'position',
    'status',
])]
final class WorkoutExerciseModel extends Model
{
    public $timestamps = false;

    protected $table = 'workout_exercises';

    /** @return BelongsTo<WorkoutSessionModel, $this> */
    public function workoutSession(): BelongsTo
    {
        return $this->belongsTo(WorkoutSessionModel::class, 'workout_session_id');
    }

    /** @return HasMany<WorkoutSetModel, $this> */
    public function workoutSets(): HasMany
    {
        return $this->hasMany(WorkoutSetModel::class, 'workout_exercise_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /** @return HasMany<WorkoutPlannedSetModel, $this> */
    public function plannedSets(): HasMany
    {
        return $this->hasMany(WorkoutPlannedSetModel::class, 'workout_exercise_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'workout_session_id' => 'integer',
            'exercise_id' => 'integer',
            'position' => 'integer',
        ];
    }
}
