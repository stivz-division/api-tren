<?php

namespace App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models;

use App\Models\Exercise;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $training_program_id
 * @property int $exercise_id
 * @property int $sets
 * @property int $repetitions_per_set
 * @property int $working_weight_grams
 * @property int $position
 * @property-read Exercise $exercise
 */
#[Fillable([
    'exercise_id',
    'sets',
    'repetitions_per_set',
    'working_weight_grams',
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'training_program_id' => 'integer',
            'exercise_id' => 'integer',
            'sets' => 'integer',
            'repetitions_per_set' => 'integer',
            'working_weight_grams' => 'integer',
            'position' => 'integer',
        ];
    }
}
