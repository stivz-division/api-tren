<?php

namespace App\WorkoutAnalysis\Infrastructure\Integrations\WorkoutPlanning;

use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutRecommendationModel;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;

final readonly class RecommendationPlanInvalidator
{
    public function __construct(private DatabaseManager $database) {}

    public function changed(int $userId, int $programId, int $exerciseId, DateTimeImmutable $now): void
    {
        $query = $this->database->table('workout_plan_change_barriers')->where('user_id', $userId)->where('training_program_id', $programId)->where('exercise_id', $exerciseId);
        $revision = $query->value('revision');
        if ($revision !== null && ! is_int($revision) && ! is_string($revision)) {
            throw new \UnexpectedValueException('Некорректная версия плана.');
        }
        $this->database->table('workout_plan_change_barriers')->updateOrInsert(
            ['user_id' => $userId, 'training_program_id' => $programId, 'exercise_id' => $exerciseId],
            ['revision' => (int) $revision + 1, 'changed_at' => $now->format('Y-m-d H:i:s.uP')],
        );
        WorkoutRecommendationModel::query()->where('user_id', $userId)->where('training_program_id', $programId)->where('exercise_id', $exerciseId)->where('status', 'proposed')->update(['status' => 'expired', 'expired_at' => $now]);
    }

    public function expireProgram(int $userId, int $programId, DateTimeImmutable $now): void
    {
        WorkoutRecommendationModel::query()->where('user_id', $userId)->where('training_program_id', $programId)->where('status', 'proposed')->update(['status' => 'expired', 'expired_at' => $now]);
    }
}
