<?php

namespace App\WorkoutAnalysis\Application\Gateways;

use App\WorkoutAnalysis\Application\DTO\RecommendationTask;

interface RecommendationTaskScheduler
{
    /** Отправляет задание после commit; задержка до availableAt применяется только к будущим повторам. Повторная доставка допустима. */
    public function schedule(RecommendationTask $task): void;
}
