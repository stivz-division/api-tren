<?php

namespace App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property int $workout_analysis_id
 * @property int $workout_session_id
 * @property int $training_program_id
 * @property int $exercise_id
 * @property int $source_revision
 * @property int|null $replacement_exercise_id
 * @property string $change_type
 * @property string $status
 * @property string $rationale
 * @property list<array{position:int,repetitions:int,working_weight_grams:int}> $original_sets
 * @property list<array{position:int,repetitions:int,working_weight_grams:int}> $proposed_sets
 * @property list<array{analysis_id:int,workout_session_id:int,exercise_id:int|null}> $evidence
 * @property CarbonImmutable $source_completed_at
 * @property CarbonImmutable|null $applied_at
 * @property CarbonImmutable|null $rejected_at
 * @property CarbonImmutable|null $expired_at
 */
final class WorkoutRecommendationModel extends Model
{
    protected $table = 'workout_recommendations';

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'id' => 'integer', 'user_id' => 'integer', 'workout_analysis_id' => 'integer',
            'workout_session_id' => 'integer', 'training_program_id' => 'integer',
            'exercise_id' => 'integer', 'source_revision' => 'integer', 'replacement_exercise_id' => 'integer',
            'original_sets' => 'array', 'proposed_sets' => 'array', 'evidence' => 'array',
            'source_completed_at' => 'immutable_datetime', 'applied_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime', 'expired_at' => 'immutable_datetime',
        ];
    }
}
