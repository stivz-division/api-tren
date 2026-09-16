<?php

namespace App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $workout_deviation_analysis_id
 * @property int $number
 * @property int $cycle_attempt
 * @property string $status
 * @property CarbonImmutable $scheduled_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $finished_at
 * @property string|null $failure_code
 */
#[Fillable([
    'workout_deviation_analysis_id',
    'number',
    'cycle_attempt',
    'status',
    'scheduled_at',
    'started_at',
    'expires_at',
    'finished_at',
    'failure_code',
])]
final class WorkoutDeviationAttemptModel extends Model
{
    protected $table = 'workout_deviation_attempts';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'workout_deviation_analysis_id' => 'integer',
            'number' => 'integer',
            'cycle_attempt' => 'integer',
            'status' => 'string',
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'failure_code' => 'string',
        ];
    }
}
