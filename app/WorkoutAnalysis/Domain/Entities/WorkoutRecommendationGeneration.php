<?php

namespace App\WorkoutAnalysis\Domain\Entities;

use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisTransition;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisAttempt;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationBatch;
use DateTimeImmutable;
use InvalidArgumentException;

/** @phpstan-type ProgramContext array{program_id:int,exercises:list<array{exercise_id:int,sets:list<array{position:int,repetitions:int,working_weight_grams:int}>,revision:int,successes:int,failures:int,completed_since_replacement:int,completed_since_rejection:int,currently_successful:bool}>,catalog:list<array{id:int,name:string}>} */
final class WorkoutRecommendationGeneration
{
    /** @param non-empty-list<AnalysisAttempt> $attempts
     * @param  ProgramContext|null  $programContext
     * @param  list<string>  $rejectedReasons
     */
    private function __construct(
        private array $attempts,
        public private(set) ?RecommendationBatch $result,
        public private(set) ?array $programContext = null,
        public private(set) array $rejectedReasons = [],
    ) {}

    public static function pending(DateTimeImmutable $now): self
    {
        return new self([new AnalysisAttempt(1, 1, AnalysisStatus::Pending, $now)], null);
    }

    /** @param array<int, AnalysisAttempt> $attempts
     * @param  ProgramContext|null  $programContext
     * @param  list<string>  $rejectedReasons
     */
    public static function restore(array $attempts, ?RecommendationBatch $result, ?array $programContext = null, array $rejectedReasons = []): self
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

        $stage = new self($attempts, $result, $programContext, $rejectedReasons);
        if (
            ($stage->status() === AnalysisStatus::Completed) !== ($result !== null)
            || ($result !== null && ($programContext === null || $result->rejectedReasons !== $rejectedReasons
                || ($result->proposals === [] && $result->rejectedReasons !== [])))
        ) {
            throw new InvalidArgumentException('Результат не соответствует состоянию или снимку анализа.');
        }

        return $stage;
    }

    /** @param ProgramContext $context */
    public function captureProgramContext(array $context): void
    {
        if ($this->programContext !== null && $this->programContext != $context) {
            throw new InvalidArgumentException('Зафиксированный контекст программы нельзя заменить.');
        }
        $this->programContext = $context;
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

    public function complete(int $number, RecommendationBatch $result, DateTimeImmutable $now): bool
    {
        if (! $this->ownsLiveAttempt($number, $now)) {
            return false;
        }

        if ($result->proposals === [] && $result->rejectedReasons !== []) {
            throw new InvalidArgumentException('Полностью отклонённые предложения не являются успешным результатом.');
        }
        $this->replaceCurrent($this->currentAttempt()->finish($now));
        $this->result = $result;
        $this->rejectedReasons = $result->rejectedReasons;

        return true;
    }

    /** @param list<string> $rejectedReasons */
    public function fail(int $number, AnalysisFailureCode $failure, DateTimeImmutable $now, ?DateTimeImmutable $retryAt, array $rejectedReasons = []): bool
    {
        if (! $this->ownsLiveAttempt($number, $now)) {
            return false;
        }

        $this->rejectedReasons = $rejectedReasons;
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
