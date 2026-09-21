<?php

namespace App\WorkoutAnalysis\Infrastructure\Integrations\WorkoutExecution;

use App\WorkoutAnalysis\Application\DTO\CompletedWorkoutData;
use App\WorkoutAnalysis\Application\Gateways\CompletedWorkoutProvider;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSessionModel;
use Illuminate\Database\DatabaseManager;

final readonly class EloquentCompletedWorkoutProvider implements CompletedWorkoutProvider
{
    public function __construct(private DatabaseManager $database, private CompletedWorkoutDataMapper $mapper) {}

    public function findForUser(WorkoutSessionId $sessionId, UserId $userId): ?CompletedWorkoutData
    {
        return $this->database->connection()->transaction(function () use ($sessionId, $userId): ?CompletedWorkoutData {
            $session = WorkoutSessionModel::query()->whereKey($sessionId->value)
                ->where('user_id', $userId->value)->sharedLock()->first();
            if ($session === null) {
                return null;
            }
            $session->load('workoutExercises.plannedSets', 'workoutExercises.workoutSets');

            return $this->mapper->toData($session);
        });
    }
}
