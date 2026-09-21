<?php

namespace App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $user_id
 * @property int $workout_session_id
 * @property array<string, mixed> $snapshot
 * @property array<string, mixed>|null $context
 * @property int|null $context_version
 * @property array<string, mixed>|null $recommendation_context
 * @property int|null $recommendation_context_version
 * @property-read WorkoutRecommendationGenerationModel|null $recommendations
 * @property int $snapshot_version
 * @property-read WorkoutDeviationAnalysisModel|null $deviations
 * @property-read WorkoutAIAnalysisModel|null $ai
 */
#[Fillable([
    'user_id',
    'workout_session_id',
    'snapshot',
    'snapshot_version',
    'context',
    'context_version',
    'recommendation_context',
    'recommendation_context_version',
])]
final class WorkoutAnalysisModel extends Model
{
    protected $table = 'workout_analyses';

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @return HasOne<WorkoutDeviationAnalysisModel, $this> */
    public function deviations(): HasOne
    {
        return $this->hasOne(WorkoutDeviationAnalysisModel::class, 'workout_analysis_id');
    }

    /** @return HasOne<WorkoutAIAnalysisModel, $this> */
    public function ai(): HasOne
    {
        return $this->hasOne(WorkoutAIAnalysisModel::class, 'workout_analysis_id');
    }

    /** @return HasOne<WorkoutRecommendationGenerationModel, $this> */
    public function recommendations(): HasOne
    {
        return $this->hasOne(WorkoutRecommendationGenerationModel::class, 'workout_analysis_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'user_id' => 'integer',
            'workout_session_id' => 'integer',
            'snapshot' => 'array',
            'snapshot_version' => 'integer',
            'context' => 'array',
            'context_version' => 'integer',
            'recommendation_context' => 'array',
            'recommendation_context_version' => 'integer',
        ];
    }
}
