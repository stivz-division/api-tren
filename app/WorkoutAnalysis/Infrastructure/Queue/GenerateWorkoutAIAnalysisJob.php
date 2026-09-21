<?php

namespace App\WorkoutAnalysis\Infrastructure\Queue;

use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\UseCases\GenerateWorkoutAIAnalysis\GenerateWorkoutAIAnalysis;
use App\WorkoutAnalysis\Application\UseCases\GenerateWorkoutAIAnalysis\GenerateWorkoutAIAnalysisInput;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;

final class GenerateWorkoutAIAnalysisJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $analysisId, public readonly int $userId, public readonly int $attemptNumber) {}

    public function handle(GenerateWorkoutAIAnalysis $generate, LoggerInterface $logger): void
    {
        $context = ['analysis_id' => $this->analysisId, 'user_id' => $this->userId, 'attempt_number' => $this->attemptNumber];
        try {
            $generate->handle(new GenerateWorkoutAIAnalysisInput($this->userId, $this->analysisId, $this->attemptNumber));
        } catch (WorkoutAnalysisNotFound) {
            $logger->info('Задание анализа тренировки пропущено: анализ больше не существует.', $context);

            return;
        }
    }
}
