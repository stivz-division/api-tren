<?php

namespace App\WorkoutAnalysis\Domain\Entities;

use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutDeviationResult;
use DateTimeImmutable;

final class WorkoutAnalysis
{
    private function __construct(
        public private(set) readonly ?WorkoutAnalysisId $id,
        private WorkoutDeviationAnalysis $deviations,
    ) {}

    public static function initialize(CompletedWorkoutSnapshot $snapshot, DateTimeImmutable $now): self
    {
        return new self(null, WorkoutDeviationAnalysis::pending($snapshot, $now));
    }

    public static function restore(WorkoutAnalysisId $id, WorkoutDeviationAnalysis $deviations): self
    {
        return new self($id, clone $deviations);
    }

    public function deviations(): WorkoutDeviationAnalysis
    {
        return clone $this->deviations;
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
