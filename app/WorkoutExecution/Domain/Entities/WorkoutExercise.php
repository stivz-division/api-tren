<?php

namespace App\WorkoutExecution\Domain\Entities;

use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\Enums\WorkoutExerciseStatus;
use App\WorkoutExecution\Domain\Exceptions\InvalidWorkoutExerciseReplacement;
use App\WorkoutExecution\Domain\Exceptions\InvalidWorkoutExerciseState;
use App\WorkoutExecution\Domain\Exceptions\WorkoutExerciseHasNoSets;
use App\WorkoutExecution\Domain\Exceptions\WorkoutExerciseIsNotEditable;
use App\WorkoutExecution\Domain\Exceptions\WorkoutReplacementRequestConflict;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutExerciseReplacement;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSet;

final class WorkoutExercise
{
    private WorkoutSetCollection $sets;

    private WorkoutSetCollection $plannedSets;

    /** @var list<WorkoutExerciseReplacement> */
    private array $replacements;

    /** @param list<WorkoutExerciseReplacement> $replacements */
    private function __construct(
        public private(set) readonly ExerciseSnapshot $snapshot,
        WorkoutSetCollection $plannedSets,
        WorkoutSetCollection $sets,
        public private(set) WorkoutExerciseStatus $status,
        array $replacements = [],
        public private(set) readonly ?int $sourcePlanRevision = null,
    ) {
        $this->plannedSets = $plannedSets->copy();
        $this->sets = $sets->copy();
        $this->assertReplacementHistory($replacements);
        $this->replacements = $replacements;
    }

    public static function fromPlan(
        ExerciseSnapshot $snapshot,
        WorkoutSetCollection $plannedSets,
        ?int $sourcePlanRevision = null,
    ): self {
        return new self(
            $snapshot,
            $plannedSets,
            $plannedSets,
            WorkoutExerciseStatus::Pending,
            [],
            $sourcePlanRevision,
        );
    }

    /** @param list<WorkoutExerciseReplacement> $replacements */
    public static function restore(
        ExerciseSnapshot $snapshot,
        WorkoutSetCollection $plannedSets,
        WorkoutSetCollection $sets,
        WorkoutExerciseStatus $status,
        array $replacements = [],
        ?int $sourcePlanRevision = null,
    ): self {
        if ($status === WorkoutExerciseStatus::Completed && $sets->isEmpty()) {
            throw new WorkoutExerciseHasNoSets($snapshot->exerciseId);
        }

        if ($status === WorkoutExerciseStatus::Skipped && ! $sets->isEmpty()) {
            throw new InvalidWorkoutExerciseState;
        }

        return new self($snapshot, $plannedSets, $sets, $status, $replacements, $sourcePlanRevision);
    }

    /** @return list<WorkoutExerciseReplacement> */
    public function replacements(): array
    {
        return $this->replacements;
    }

    public function latestReplacement(): ?WorkoutExerciseReplacement
    {
        return $this->replacements === [] ? null : $this->replacements[array_key_last($this->replacements)];
    }

    public function effectiveSnapshot(): ExerciseSnapshot
    {
        return $this->latestReplacement()->snapshot ?? $this->snapshot;
    }

    /** @return list<WorkoutSet> */
    public function effectivePlannedSets(): array
    {
        return $this->latestReplacement()?->plannedSets() ?? $this->plannedSets();
    }

    public function includedInAnalysis(): bool
    {
        return $this->latestReplacement()->replaceInPlan ?? true;
    }

    public function planBaselineSnapshot(): ExerciseSnapshot
    {
        return $this->lastPermanentReplacement()->snapshot ?? $this->snapshot;
    }

    /** @return list<WorkoutSet> */
    public function planBaselineSets(): array
    {
        return $this->lastPermanentReplacement()?->plannedSets() ?? $this->plannedSets();
    }

    public function planBaselineRevision(): ?int
    {
        $replacement = $this->lastPermanentReplacement();

        return $replacement === null ? $this->sourcePlanRevision : $replacement->planRevisionAfter;
    }

    public function isReplacementReplay(
        string $requestId,
        ExerciseId $exerciseId,
        bool $replaceInPlan,
        WorkoutSetCollection $sets,
    ): bool {
        foreach ($this->replacements as $replacement) {
            if ($replacement->requestId !== strtolower($requestId)) {
                continue;
            }

            if (! $replacement->matchesPayload($exerciseId, $replaceInPlan, $sets)) {
                throw new WorkoutReplacementRequestConflict($replacement->requestId);
            }

            return true;
        }

        return false;
    }

    public function assertCanReplace(ExerciseId $exerciseId): void
    {
        $this->assertEditable();

        if ($this->effectiveSnapshot()->exerciseId->value === $exerciseId->value) {
            throw new InvalidWorkoutExerciseReplacement;
        }
    }

    public function replace(WorkoutExerciseReplacement $replacement): bool
    {
        $sets = new WorkoutSetCollection(...$replacement->plannedSets());

        if ($this->isReplacementReplay($replacement->requestId, $replacement->snapshot->exerciseId, $replacement->replaceInPlan, $sets)) {
            return false;
        }

        $this->assertCanReplace($replacement->snapshot->exerciseId);

        if (
            $replacement->sequence !== count($this->replacements) + 1
            || $replacement->snapshot->position->value !== $this->snapshot->position->value
        ) {
            throw new InvalidWorkoutExerciseReplacement;
        }

        $this->replacements[] = $replacement;
        $this->sets = $sets->copy();

        return true;
    }

    /** @return list<WorkoutSet> */
    public function plannedSets(): array
    {
        return $this->plannedSets->copy()->all();
    }

    /** @return list<WorkoutSet> */
    public function workoutSets(): array
    {
        return $this->sets->copy()->all();
    }

    public function saveProgress(WorkoutSetCollection $sets): void
    {
        $this->assertEditable();

        $this->sets = $sets->copy();
    }

    public function complete(WorkoutSetCollection $sets): void
    {
        if (
            $this->status === WorkoutExerciseStatus::Completed
            && $this->sets->equals($sets)
        ) {
            return;
        }

        $this->assertEditable();

        if ($sets->isEmpty()) {
            throw new WorkoutExerciseHasNoSets($this->snapshot->exerciseId);
        }

        $this->sets = $sets->copy();
        $this->status = WorkoutExerciseStatus::Completed;
    }

    public function skip(): void
    {
        if ($this->status === WorkoutExerciseStatus::Skipped) {
            return;
        }

        $this->assertEditable();

        $this->sets = new WorkoutSetCollection;
        $this->status = WorkoutExerciseStatus::Skipped;
    }

    public function reopen(): void
    {
        if ($this->status === WorkoutExerciseStatus::Skipped) {
            $this->sets = new WorkoutSetCollection(...$this->effectivePlannedSets());
        }

        $this->status = WorkoutExerciseStatus::Pending;
    }

    public function __clone(): void
    {
        $this->plannedSets = $this->plannedSets->copy();
        $this->sets = $this->sets->copy();
    }

    private function assertEditable(): void
    {
        if ($this->status !== WorkoutExerciseStatus::Pending) {
            throw new WorkoutExerciseIsNotEditable(
                $this->snapshot->exerciseId,
                $this->status,
            );
        }
    }

    private function lastPermanentReplacement(): ?WorkoutExerciseReplacement
    {
        foreach (array_reverse($this->replacements) as $replacement) {
            if ($replacement->replaceInPlan) {
                return $replacement;
            }
        }

        return null;
    }

    /** @param list<WorkoutExerciseReplacement> $replacements */
    private function assertReplacementHistory(array $replacements): void
    {
        $requestIds = [];
        $previousExerciseId = $this->snapshot->exerciseId->value;

        foreach ($replacements as $index => $replacement) {
            if (
                $replacement->sequence !== $index + 1
                || $replacement->snapshot->position->value !== $this->snapshot->position->value
                || $replacement->snapshot->exerciseId->value === $previousExerciseId
                || isset($requestIds[$replacement->requestId])
            ) {
                throw new InvalidWorkoutExerciseReplacement;
            }

            $requestIds[$replacement->requestId] = true;
            $previousExerciseId = $replacement->snapshot->exerciseId->value;
        }
    }
}
