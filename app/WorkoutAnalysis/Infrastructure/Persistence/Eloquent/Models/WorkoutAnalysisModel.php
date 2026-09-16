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
 * @property int $snapshot_version
 * @property-read WorkoutDeviationAnalysisModel|null $deviations
 */
#[Fillable([
    'user_id',
    'workout_session_id',
    'snapshot',
    'snapshot_version',
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'user_id' => 'integer',
            'workout_session_id' => 'integer',
            'snapshot' => 'array',
            'snapshot_version' => 'integer',
        ];
    }
}
