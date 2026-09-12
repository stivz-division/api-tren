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
 * @property int $planned_sets
 * @property int $planned_repetitions_per_set
 * @property int $planned_working_weight_grams
 * @property string $status
 * @property-read Collection<int, WorkoutSetModel> $workoutSets
 */
#[Fillable([
    'exercise_id',
    'exercise_name',
    'position',
    'planned_sets',
    'planned_repetitions_per_set',
    'planned_working_weight_grams',
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'workout_session_id' => 'integer',
            'exercise_id' => 'integer',
            'position' => 'integer',
            'planned_sets' => 'integer',
            'planned_repetitions_per_set' => 'integer',
            'planned_working_weight_grams' => 'integer',
        ];
    }
}
