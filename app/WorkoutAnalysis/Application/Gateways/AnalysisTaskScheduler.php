<?php

namespace App\WorkoutAnalysis\Application\Gateways;

use App\WorkoutAnalysis\Application\DTO\DeviationTask;

interface AnalysisTaskScheduler
{
    /** Отправляет задание после commit, с задержкой до availableAt. Повторная доставка допустима. */
    public function schedule(DeviationTask $task): void;
}
