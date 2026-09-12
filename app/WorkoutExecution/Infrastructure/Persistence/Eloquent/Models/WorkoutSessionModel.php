<?php

namespace App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $training_program_id
 * @property string $training_program_name
 * @property int $scheduled_weekday
 * @property string $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property-read Collection<int, WorkoutExerciseModel> $workoutExercises
 */
#[Fillable([
    'user_id',
    'training_program_id',
    'training_program_name',
    'scheduled_weekday',
    'status',
    'started_at',
    'completed_at',
    'cancelled_at',
])]
final class WorkoutSessionModel extends Model
{
    protected $table = 'workout_sessions';

    /** @return HasMany<WorkoutExerciseModel, $this> */
    public function workoutExercises(): HasMany
    {
        return $this->hasMany(WorkoutExerciseModel::class, 'workout_session_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'user_id' => 'integer',
            'training_program_id' => 'integer',
            'scheduled_weekday' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
