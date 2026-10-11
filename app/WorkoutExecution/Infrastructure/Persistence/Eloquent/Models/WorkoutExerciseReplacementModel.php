<?php

namespace App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workout_exercise_id
 * @property int $sequence
 * @property string $request_id
 * @property int $exercise_id
 * @property string $exercise_name
 * @property bool $replace_in_plan
 * @property CarbonImmutable $created_at
 * @property int|null $plan_revision_after
 * @property-read Collection<int, WorkoutReplacementPlannedSetModel> $plannedSets
 */
#[Fillable([
    'sequence',
    'request_id',
    'exercise_id',
    'exercise_name',
    'replace_in_plan',
    'created_at',
    'plan_revision_after',
])]
final class WorkoutExerciseReplacementModel extends Model
{
    public $timestamps = false;

    protected $table = 'workout_exercise_replacements';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return BelongsTo<WorkoutExerciseModel, $this> */
    public function workoutExercise(): BelongsTo
    {
        return $this->belongsTo(WorkoutExerciseModel::class, 'workout_exercise_id');
    }

    /** @return HasMany<WorkoutReplacementPlannedSetModel, $this> */
    public function plannedSets(): HasMany
    {
        return $this->hasMany(WorkoutReplacementPlannedSetModel::class, 'replacement_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'workout_exercise_id' => 'integer',
            'sequence' => 'integer',
            'exercise_id' => 'integer',
            'replace_in_plan' => 'boolean',
            'created_at' => 'immutable_datetime',
            'plan_revision_after' => 'integer',
        ];
    }
}
