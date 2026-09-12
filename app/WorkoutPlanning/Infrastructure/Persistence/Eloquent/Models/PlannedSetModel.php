<?php

namespace App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $planned_exercise_id
 * @property int $position
 * @property int $repetitions
 * @property int $working_weight_grams
 */
#[Fillable([
    'position',
    'repetitions',
    'working_weight_grams',
])]
final class PlannedSetModel extends Model
{
    public $timestamps = false;

    protected $table = 'planned_sets';

    /** @return BelongsTo<PlannedExerciseModel, $this> */
    public function plannedExercise(): BelongsTo
    {
        return $this->belongsTo(PlannedExerciseModel::class, 'planned_exercise_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'planned_exercise_id' => 'integer',
            'position' => 'integer',
            'repetitions' => 'integer',
            'working_weight_grams' => 'integer',
        ];
    }
}
