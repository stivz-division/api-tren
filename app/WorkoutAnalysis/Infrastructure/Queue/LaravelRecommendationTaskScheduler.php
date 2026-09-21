<?php

namespace App\WorkoutAnalysis\Infrastructure\Queue;

use App\WorkoutAnalysis\Application\DTO\RecommendationTask;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\RecommendationTaskScheduler;
use Illuminate\Contracts\Bus\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class LaravelRecommendationTaskScheduler implements RecommendationTaskScheduler
{
    public function __construct(private Dispatcher $bus, private LoggerInterface $logger, private AnalysisClock $clock) {}

    /** Ошибка доставки после commit оставляет Pending для служебного восстановления. */
    public function schedule(RecommendationTask $task): void
    {
        $context = ['analysis_id' => $task->analysisId, 'user_id' => $task->userId, 'attempt_number' => $task->attemptNumber];
        try {
            $job = new GenerateWorkoutRecommendationsJob($task->analysisId, $task->userId, $task->attemptNumber);
            $job->onConnection((string) config('workout-analysis.queue.connection'))
                ->onQueue((string) config('workout-analysis.queue.name'))
                ->afterCommit();
            if ($task->attemptNumber > 1 && $task->availableAt > $this->clock->now()) {
                $availableAt = $task->availableAt;
                if ($availableAt->format('u') !== '000000') {
                    $availableAt = $availableAt->setTimestamp($availableAt->getTimestamp() + 1);
                }
                $job->delay($availableAt);
            }
            $this->bus->dispatch($job);
        } catch (Throwable $exception) {
            $this->logger->error('Не удалось отправить задание анализа тренировки; ожидающая попытка будет отправлена повторно при восстановлении.', $context + ['exception' => $exception]);
        }
    }
}
