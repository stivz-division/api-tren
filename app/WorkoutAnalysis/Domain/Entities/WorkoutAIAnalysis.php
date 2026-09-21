<?php

namespace App\WorkoutAnalysis\Domain\Entities;

use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisTransition;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisAttempt;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAIResult;
use DateTimeImmutable;
use InvalidArgumentException;

final class WorkoutAIAnalysis
{
    /** @param non-empty-list<AnalysisAttempt> $attempts */
    private function __construct(
        private array $attempts,
        public private(set) ?WorkoutAIResult $result,
    ) {}

    public static function pending(DateTimeImmutable $now): self
    {
        return new self([new AnalysisAttempt(1, 1, AnalysisStatus::Pending, $now)], null);
    }

    /** @param array<int, AnalysisAttempt> $attempts */
    public static function restore(array $attempts, ?WorkoutAIResult $result): self
    {
        if ($attempts === [] || ! array_is_list($attempts)) {
            throw new InvalidArgumentException('У этапа должна быть история попыток.');
        }

        $previous = null;
        foreach ($attempts as $index => $attempt) {
            if (
                $attempt->number !== $index + 1
                || ($previous === null && $attempt->cycleAttempt !== 1)
                || ($previous !== null && (
                    $previous->status !== AnalysisStatus::Failed
                    || $attempt->scheduledAt < $previous->finishedAt
                    || ! in_array($attempt->cycleAttempt, [1, $previous->cycleAttempt + 1], true)
                ))
            ) {
                throw new InvalidArgumentException('Некорректная история попыток анализа.');
            }
            $previous = $attempt;
        }

        $stage = new self($attempts, $result);
        if (
            ($stage->status() === AnalysisStatus::Completed) !== ($result !== null)
        ) {
            throw new InvalidArgumentException('Результат не соответствует состоянию или снимку анализа.');
        }

        return $stage;
    }

    public function status(): AnalysisStatus
    {
        return $this->currentAttempt()->status;
    }

    public function currentAttempt(): AnalysisAttempt
    {
        return $this->attempts[array_key_last($this->attempts)];
    }

    /** @return non-empty-list<AnalysisAttempt> */
    public function attempts(): array
    {
        return $this->attempts;
    }

    public function start(int $number, DateTimeImmutable $now, DateTimeImmutable $expiresAt): bool
    {
        $attempt = $this->currentAttempt();
        if ($attempt->number !== $number || $attempt->status !== AnalysisStatus::Pending || $now < $attempt->scheduledAt) {
            return false;
        }

        $this->replaceCurrent($attempt->start($now, $expiresAt));

        return true;
    }

    public function complete(int $number, WorkoutAIResult $result, DateTimeImmutable $now): bool
    {
        if (! $this->ownsLiveAttempt($number, $now)) {
            return false;
        }

        $this->replaceCurrent($this->currentAttempt()->finish($now));
        $this->result = $result;

        return true;
    }

    public function fail(int $number, AnalysisFailureCode $failure, DateTimeImmutable $now, ?DateTimeImmutable $retryAt): bool
    {
        if (! $this->ownsLiveAttempt($number, $now)) {
            return false;
        }

        $this->finishFailure($failure, $now, $retryAt);

        return true;
    }

    public function retry(DateTimeImmutable $now): bool
    {
        if ($this->status() === AnalysisStatus::Processing) {
            throw new InvalidAnalysisTransition;
        }

        if ($this->status() !== AnalysisStatus::Failed) {
            return false;
        }

        if ($now < $this->currentAttempt()->finishedAt) {
            throw new InvalidArgumentException('Повтор не может предшествовать завершению попытки.');
        }

        $this->attempts[] = new AnalysisAttempt($this->currentAttempt()->number + 1, 1, AnalysisStatus::Pending, $now);

        return true;
    }

    public function recoverExpired(DateTimeImmutable $now, ?DateTimeImmutable $retryAt): bool
    {
        $attempt = $this->currentAttempt();
        if ($attempt->status !== AnalysisStatus::Processing || $attempt->expiresAt > $now) {
            return false;
        }

        $this->finishFailure(AnalysisFailureCode::AttemptTimedOut, $now, $retryAt);

        return true;
    }

    private function ownsLiveAttempt(int $number, DateTimeImmutable $now): bool
    {
        $attempt = $this->currentAttempt();

        return $attempt->number === $number
            && $attempt->status === AnalysisStatus::Processing
            && $now < $attempt->expiresAt;
    }

    private function finishFailure(AnalysisFailureCode $failure, DateTimeImmutable $now, ?DateTimeImmutable $retryAt): void
    {
        if ($retryAt !== null && $retryAt < $now) {
            throw new InvalidArgumentException('Повтор не может предшествовать ошибке.');
        }

        $attempt = $this->currentAttempt()->finish($now, $failure);
        $this->replaceCurrent($attempt);

        if ($retryAt !== null) {
            $this->attempts[] = new AnalysisAttempt($attempt->number + 1, $attempt->cycleAttempt + 1, AnalysisStatus::Pending, $retryAt);
        }
    }

    private function replaceCurrent(AnalysisAttempt $attempt): void
    {
        $this->attempts = [...array_slice($this->attempts, 0, -1), $attempt];
    }
}
