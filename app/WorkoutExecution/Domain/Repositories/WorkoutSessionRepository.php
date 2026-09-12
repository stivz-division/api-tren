<?php

namespace App\WorkoutExecution\Domain\Repositories;

use App\WorkoutExecution\Domain\Entities\WorkoutSession;
use App\WorkoutExecution\Domain\Exceptions\ActiveWorkoutSessionAlreadyExists;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;
use LogicException;

interface WorkoutSessionRepository
{
    public function findForUser(WorkoutSessionId $id, UserId $userId): ?WorkoutSession;

    public function findActiveForUser(UserId $userId): ?WorkoutSession;

    /**
     * Атомарно сохраняет новую сессию и гарантирует не более одной активной
     * тренировочной сессии на пользователя.
     *
     * @throws ActiveWorkoutSessionAlreadyExists
     * @throws LogicException Если агрегат уже имеет идентификатор
     */
    public function add(WorkoutSession $session): WorkoutSession;

    /**
     * @throws LogicException Если агрегат ещё не имеет идентификатора
     */
    public function save(WorkoutSession $session): void;
}
