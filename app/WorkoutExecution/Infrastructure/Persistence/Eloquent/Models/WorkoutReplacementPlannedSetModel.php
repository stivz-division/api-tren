<?php

namespace App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $replacement_id
 * @property int $position
 * @property int $repetitions
 * @property int $working_weight_grams
 */
#[Fillable(['position', 'repetitions', 'working_weight_grams'])]
final class WorkoutReplacementPlannedSetModel extends Model
{
    public $timestamps = false;

    protected $table = 'workout_replacement_planned_sets';

    /** @return BelongsTo<WorkoutExerciseReplacementModel, $this> */
    public function replacement(): BelongsTo
    {
        return $this->belongsTo(WorkoutExerciseReplacementModel::class, 'replacement_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'replacement_id' => 'integer',
            'position' => 'integer',
            'repetitions' => 'integer',
            'working_weight_grams' => 'integer',
        ];
    }
}
