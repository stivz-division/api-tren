<?php

namespace App\WorkoutAnalysis\Domain\Entities;

use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisTransition;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAIResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutDeviationResult;
use DateTimeImmutable;

final class WorkoutAnalysis
{
    private ?AnalysisContextSnapshot $context = null;

    private ?WorkoutAIAnalysis $ai = null;

    private function __construct(
        public private(set) readonly ?WorkoutAnalysisId $id,
        private WorkoutDeviationAnalysis $deviations,
    ) {}

    public static function initialize(CompletedWorkoutSnapshot $snapshot, DateTimeImmutable $now): self
    {
        return new self(null, WorkoutDeviationAnalysis::pending($snapshot, $now));
    }

    public static function restore(WorkoutAnalysisId $id, WorkoutDeviationAnalysis $deviations, ?AnalysisContextSnapshot $context = null, ?WorkoutAIAnalysis $ai = null): self
    {
        $analysis = new self($id, clone $deviations);
        if ($context !== null) {
            $analysis->attachContext($context);
        }

        if ($ai !== null) {
            $finishedAt = $deviations->currentAttempt()->finishedAt;
            if ($deviations->status() !== AnalysisStatus::Completed || $finishedAt === null || $ai->attempts()[0]->scheduledAt < $finishedAt) {
                throw new InvalidAnalysisTransition;
            }
            if ($ai->result !== null && ($ai->result->context != $context || $ai->result->conclusion->analysisId != $id)) {
                throw new InvalidAnalysisContext('Заключение не соответствует анализу или его контексту.');
            }
            $analysis->ai = clone $ai;
        }

        return $analysis;
    }

    public function deviations(): WorkoutDeviationAnalysis
    {
        return clone $this->deviations;
    }

    public function context(): ?AnalysisContextSnapshot
    {
        return $this->context;
    }

    public function attachContext(AnalysisContextSnapshot $context): bool
    {
        $finishedAt = $this->deviations->currentAttempt()->finishedAt;
        if (
            $this->deviations->status() !== AnalysisStatus::Completed
            || $this->deviations->result != $context->currentWorkout
            || $finishedAt === null
            || $context->capturedAt < $finishedAt
        ) {
            throw new InvalidAnalysisContext('Контекст должен соответствовать готовым отклонениям текущего анализа.');
        }

        if ($this->context !== null) {
            if ($this->context != $context) {
                throw new InvalidAnalysisContext('Зафиксированный контекст анализа нельзя заменить.');
            }

            return false;
        }

        $this->context = $context;

        return true;
    }

    public function startDeviationAttempt(int $number, DateTimeImmutable $now, DateTimeImmutable $expiresAt): bool
    {
        return $this->deviations->start($number, $now, $expiresAt);
    }

    public function completeDeviationAttempt(int $number, WorkoutDeviationResult $result, DateTimeImmutable $now): bool
    {
        return $this->deviations->complete($number, $result, $now);
    }

    public function failDeviationAttempt(int $number, AnalysisFailureCode $failure, DateTimeImmutable $now, ?DateTimeImmutable $retryAt): bool
    {
        return $this->deviations->fail($number, $failure, $now, $retryAt);
    }

    public function retryDeviations(DateTimeImmutable $now): bool
    {
        return $this->deviations->retry($now);
    }

    public function recoverExpiredDeviationAttempt(DateTimeImmutable $now, ?DateTimeImmutable $retryAt): bool
    {
        return $this->deviations->recoverExpired($now, $retryAt);
    }

    public function ai(): ?WorkoutAIAnalysis
    {
        return $this->ai === null ? null : clone $this->ai;
    }

    public function scheduleAI(DateTimeImmutable $now): bool
    {
        if ($this->ai !== null) {
            return false;
        }
        if ($this->deviations->status() !== AnalysisStatus::Completed || $now < $this->deviations->currentAttempt()->finishedAt) {
            throw new InvalidAnalysisTransition;
        }
        $this->ai = WorkoutAIAnalysis::pending($now);

        return true;
    }

    public function startAIAttempt(int $number, DateTimeImmutable $now, DateTimeImmutable $expiresAt): bool
    {
        return $this->ai?->start($number, $now, $expiresAt) ?? false;
    }

    public function completeAIAttempt(int $number, WorkoutAIResult $result, DateTimeImmutable $now): bool
    {
        if ($result->context != $this->context || $result->conclusion->analysisId != $this->id) {
            throw new InvalidAnalysisContext('Заключение не соответствует зафиксированному контексту анализа.');
        }

        return $this->ai?->complete($number, $result, $now) ?? false;
    }

    public function failAIAttempt(int $number, AnalysisFailureCode $failure, DateTimeImmutable $now, ?DateTimeImmutable $retryAt): bool
    {
        return $this->ai?->fail($number, $failure, $now, $retryAt) ?? false;
    }

    public function retryAI(DateTimeImmutable $now): bool
    {
        return $this->ai?->retry($now) ?? false;
    }

    public function recoverExpiredAIAttempt(DateTimeImmutable $now, ?DateTimeImmutable $retryAt): bool
    {
        return $this->ai?->recoverExpired($now, $retryAt) ?? false;
    }

    public function __clone(): void
    {
        $this->deviations = clone $this->deviations;
        $this->ai = $this->ai === null ? null : clone $this->ai;
    }
}
