<?php

namespace App\WorkoutAnalysis\Application\Gateways;

use App\WorkoutAnalysis\Application\DTO\DeviationTask;

interface AnalysisTaskScheduler
{
    /** Отправляет задание после commit; задержка до availableAt применяется только к будущим повторам. Повторная доставка допустима. */
    public function schedule(DeviationTask $task): void;
}
