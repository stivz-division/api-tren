<?php

namespace App\WorkoutAnalysis\Application\Gateways;

use App\WorkoutAnalysis\Application\DTO\AIAnalysisTask;

interface AIAnalysisTaskScheduler
{
    /** Отправляет задание после commit; задержка до availableAt применяется только к будущим повторам. Повторная доставка допустима. */
    public function schedule(AIAnalysisTask $task): void;
}
