<?php

namespace App\WorkoutAnalysis\Domain\Entities;

use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutDeviationResult;
use DateTimeImmutable;

final class WorkoutAnalysis
{
    private ?AnalysisContextSnapshot $context = null;

    private function __construct(
        public private(set) readonly ?WorkoutAnalysisId $id,
        private WorkoutDeviationAnalysis $deviations,
    ) {}

    public static function initialize(CompletedWorkoutSnapshot $snapshot, DateTimeImmutable $now): self
    {
        return new self(null, WorkoutDeviationAnalysis::pending($snapshot, $now));
    }

    public static function restore(WorkoutAnalysisId $id, WorkoutDeviationAnalysis $deviations, ?AnalysisContextSnapshot $context = null): self
    {
        $analysis = new self($id, clone $deviations);
        if ($context !== null) {
            $analysis->attachContext($context);
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

    public function __clone(): void
    {
        $this->deviations = clone $this->deviations;
    }
}
