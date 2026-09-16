<?php

namespace App\WorkoutAnalysis\Application\Policies;

use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisAttempt;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AnalysisExecutionPolicy
{
    /** @var non-empty-list<int> */
    public private(set) array $retryDelaysInSeconds;

    /** @param array<int, int> $retryDelaysInSeconds */
    public function __construct(
        public private(set) int $maxAttempts = 3,
        public private(set) int $processingTimeoutInSeconds = 120,
        public private(set) int $pendingRecoveryDelayInSeconds = 60,
        array $retryDelaysInSeconds = [5, 30],
    ) {
        if ($maxAttempts < 1 || $processingTimeoutInSeconds < 1 || $pendingRecoveryDelayInSeconds < 1
            || $retryDelaysInSeconds === [] || ! array_is_list($retryDelaysInSeconds)) {
            throw new InvalidArgumentException('Некорректная политика выполнения анализа.');
        }
        foreach ($retryDelaysInSeconds as $delay) {
            if ($delay < 0) {
                throw new InvalidArgumentException('Задержка повтора не может быть отрицательной.');
            }
        }
        $this->retryDelaysInSeconds = $retryDelaysInSeconds;
    }

    public function expiresAt(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify("+{$this->processingTimeoutInSeconds} seconds");
    }

    public function retryAt(AnalysisAttempt $attempt, AnalysisFailureCode $failure, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if (
            ! in_array($failure, [AnalysisFailureCode::WorkerFailed, AnalysisFailureCode::AttemptTimedOut], true)
            || $attempt->cycleAttempt >= $this->maxAttempts
        ) {
            return null;
        }

        $delay = $this->retryDelaysInSeconds[min($attempt->cycleAttempt - 1, count($this->retryDelaysInSeconds) - 1)];

        return $now->modify("+{$delay} seconds");
    }

    public function pendingNeedsDispatch(AnalysisAttempt $attempt, DateTimeImmutable $now): bool
    {
        return $attempt->status === AnalysisStatus::Pending
            && $now >= $attempt->scheduledAt->modify("+{$this->pendingRecoveryDelayInSeconds} seconds");
    }
}
