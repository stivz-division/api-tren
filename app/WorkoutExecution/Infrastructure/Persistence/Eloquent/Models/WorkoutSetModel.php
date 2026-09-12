<?php

namespace App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workout_exercise_id
 * @property int $position
 * @property int $repetitions
 * @property int $working_weight_grams
 */
#[Fillable([
    'position',
    'repetitions',
    'working_weight_grams',
])]
final class WorkoutSetModel extends Model
{
    public $timestamps = false;

    protected $table = 'workout_sets';

    /** @return BelongsTo<WorkoutExerciseModel, $this> */
    public function workoutExercise(): BelongsTo
    {
        return $this->belongsTo(WorkoutExerciseModel::class, 'workout_exercise_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'workout_exercise_id' => 'integer',
            'position' => 'integer',
            'repetitions' => 'integer',
            'working_weight_grams' => 'integer',
        ];
    }
}
