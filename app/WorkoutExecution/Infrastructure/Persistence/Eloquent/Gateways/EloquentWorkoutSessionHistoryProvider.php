<?php

namespace App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Gateways;

use App\WorkoutExecution\Application\DTO\WorkoutSessionDTO;
use App\WorkoutExecution\Application\DTO\WorkoutSessionHistoryPageDTO;
use App\WorkoutExecution\Application\Gateways\WorkoutSessionHistoryProvider;
use App\WorkoutExecution\Domain\Enums\WorkoutSessionStatus;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Mappers\WorkoutSessionMapper;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSessionModel;

final readonly class EloquentWorkoutSessionHistoryProvider implements WorkoutSessionHistoryProvider
{
    public function __construct(private WorkoutSessionMapper $mapper) {}

    public function paginateForUser(
        UserId $userId,
        int $perPage,
        ?string $cursor,
    ): WorkoutSessionHistoryPageDTO {
        $paginator = WorkoutSessionModel::query()
            ->where('user_id', $userId->value)
            ->whereIn('status', [
                WorkoutSessionStatus::Completed->value,
                WorkoutSessionStatus::Cancelled->value,
            ])
            ->with('workoutExercises.plannedSets', 'workoutExercises.workoutSets')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage, ['*'], 'cursor', $cursor);

        $sessions = array_values(
            $paginator->getCollection()
                ->map(fn (WorkoutSessionModel $model): WorkoutSessionDTO => WorkoutSessionDTO::fromDomain(
                    $this->mapper->toDomain($model),
                ))
                ->all(),
        );

        return new WorkoutSessionHistoryPageDTO(
            sessions: $sessions,
            perPage: $paginator->perPage(),
            nextCursor: $paginator->nextCursor()?->encode(),
            previousCursor: $paginator->previousCursor()?->encode(),
        );
    }
}
