<?php

namespace App\WorkoutExecution\Domain\Entities;

use App\WorkoutExecution\Domain\Collections\WorkoutExerciseCollection;
use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\Enums\WorkoutSessionStatus;
use App\WorkoutExecution\Domain\Exceptions\InvalidWorkoutSessionState;
use App\WorkoutExecution\Domain\Exceptions\WorkoutResolutionBeforeStart;
use App\WorkoutExecution\Domain\Exceptions\WorkoutSessionHasPendingExercises;
use App\WorkoutExecution\Domain\Exceptions\WorkoutSessionIsNotInProgress;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;
use DateTimeImmutable;

final class WorkoutSession
{
    private WorkoutExerciseCollection $exercises;

    private function __construct(
        public private(set) readonly ?WorkoutSessionId $id,
        public private(set) readonly UserId $userId,
        public private(set) readonly TrainingProgramSnapshot $programSnapshot,
        WorkoutExerciseCollection $exercises,
        public private(set) WorkoutSessionStatus $status,
        public private(set) readonly DateTimeImmutable $startedAt,
        public private(set) ?DateTimeImmutable $completedAt,
        public private(set) ?DateTimeImmutable $cancelledAt,
    ) {
        $this->exercises = $exercises->copy();
    }

    public static function start(
        UserId $userId,
        TrainingProgramSnapshot $programSnapshot,
        WorkoutExerciseCollection $exercises,
        DateTimeImmutable $startedAt,
    ): self {
        return new self(
            null,
            $userId,
            $programSnapshot,
            $exercises->freshFromPlan(),
            WorkoutSessionStatus::InProgress,
            $startedAt,
            null,
            null,
        );
    }

    public static function restore(
        WorkoutSessionId $id,
        UserId $userId,
        TrainingProgramSnapshot $programSnapshot,
        WorkoutExerciseCollection $exercises,
        WorkoutSessionStatus $status,
        DateTimeImmutable $startedAt,
        ?DateTimeImmutable $completedAt,
        ?DateTimeImmutable $cancelledAt,
    ): self {
        $session = new self(
            $id,
            $userId,
            $programSnapshot,
            $exercises,
            $status,
            $startedAt,
            $completedAt,
            $cancelledAt,
        );
        $session->assertRestoredState();

        return $session;
    }

    /** @return non-empty-list<WorkoutExercise> */
    public function workoutExercises(): array
    {
        return $this->exercises->copy()->all();
    }

    public function saveExerciseProgress(
        ExerciseId $exerciseId,
        WorkoutSetCollection $sets,
    ): void {
        $this->assertInProgress();

        $this->exercises->get($exerciseId)->saveProgress($sets);
    }

    public function completeExercise(
        ExerciseId $exerciseId,
        WorkoutSetCollection $sets,
    ): void {
        $this->assertInProgress();

        $this->exercises->get($exerciseId)->complete($sets);
    }

    public function skipExercise(ExerciseId $exerciseId): void
    {
        $this->assertInProgress();

        $this->exercises->get($exerciseId)->skip();
    }

    public function reopenExercise(ExerciseId $exerciseId): void
    {
        $this->assertInProgress();

        $this->exercises->get($exerciseId)->reopen();
    }

    public function complete(DateTimeImmutable $completedAt): void
    {
        if ($this->status === WorkoutSessionStatus::Completed) {
            return;
        }

        $this->assertInProgress();

        if (! $this->exercises->allResolved()) {
            throw new WorkoutSessionHasPendingExercises;
        }

        $this->assertResolutionNotBeforeStart($completedAt);

        $this->status = WorkoutSessionStatus::Completed;
        $this->completedAt = $completedAt;
    }

    public function cancel(DateTimeImmutable $cancelledAt): void
    {
        if ($this->status === WorkoutSessionStatus::Cancelled) {
            return;
        }

        $this->assertInProgress();
        $this->assertResolutionNotBeforeStart($cancelledAt);

        $this->status = WorkoutSessionStatus::Cancelled;
        $this->cancelledAt = $cancelledAt;
    }

    private function assertInProgress(): void
    {
        if ($this->status !== WorkoutSessionStatus::InProgress) {
            throw new WorkoutSessionIsNotInProgress($this->status);
        }
    }

    private function assertResolutionNotBeforeStart(DateTimeImmutable $resolvedAt): void
    {
        if ($resolvedAt < $this->startedAt) {
            throw new WorkoutResolutionBeforeStart;
        }
    }

    private function assertRestoredState(): void
    {
        $isValid = match ($this->status) {
            WorkoutSessionStatus::InProgress => $this->completedAt === null
                && $this->cancelledAt === null,
            WorkoutSessionStatus::Completed => $this->completedAt !== null
                && $this->cancelledAt === null
                && $this->exercises->allResolved()
                && $this->completedAt >= $this->startedAt,
            WorkoutSessionStatus::Cancelled => $this->cancelledAt !== null
                && $this->completedAt === null
                && $this->cancelledAt >= $this->startedAt,
        };

        if (! $isValid) {
            throw new InvalidWorkoutSessionState;
        }
    }
}
