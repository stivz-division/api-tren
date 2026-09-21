<?php

namespace App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $workout_analysis_id
 * @property string $status
 * @property int $current_attempt_number
 * @property CarbonImmutable $scheduled_at
 * @property CarbonImmutable|null $expires_at
 * @property array<string, mixed>|null $result
 * @property int|null $result_version
 * @property-read WorkoutAnalysisModel|null $analysis
 * @property-read Collection<int, WorkoutAIAttemptModel> $attempts
 */
#[Fillable([
    'workout_analysis_id',
    'status',
    'current_attempt_number',
    'scheduled_at',
    'expires_at',
    'result',
    'result_version',
])]
final class WorkoutAIAnalysisModel extends Model
{
    protected $table = 'workout_ai_analyses';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @return BelongsTo<WorkoutAnalysisModel, $this> */
    public function analysis(): BelongsTo
    {
        return $this->belongsTo(WorkoutAnalysisModel::class, 'workout_analysis_id');
    }

    /** @return HasMany<WorkoutAIAttemptModel, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(WorkoutAIAttemptModel::class, 'workout_ai_analysis_id')->orderBy('number');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'workout_analysis_id' => 'integer',
            'status' => 'string',
            'current_attempt_number' => 'integer',
            'scheduled_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'result' => 'array',
            'result_version' => 'integer',
        ];
    }
}
