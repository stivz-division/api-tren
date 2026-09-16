<?php

namespace App\WorkoutAnalysis\Domain\Repositories;

use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;

interface WorkoutAnalysisRepository
{
    public function findForUser(WorkoutAnalysisId $id, UserId $userId): ?WorkoutAnalysis;

    public function findForSession(WorkoutSessionId $sessionId, UserId $userId): ?WorkoutAnalysis;

    /** Сохраняет новый агрегат, присваивает ID; хранилище гарантирует уникальность workoutSessionId. */
    public function add(WorkoutAnalysis $analysis): WorkoutAnalysis;

    /** Вызывается внутри AnalysisTransaction; сохраняет этап, результат и историю атомарно. */
    public function save(WorkoutAnalysis $analysis): void;
}
