<?php

namespace App\WorkoutExecution\Domain\ValueObjects;

use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\Exceptions\InvalidWorkoutExerciseReplacement;
use App\WorkoutExecution\Domain\Exceptions\WorkoutExerciseHasNoSets;
use DateTimeImmutable;
use DateTimeZone;

final readonly class WorkoutExerciseReplacement
{
    private WorkoutSetCollection $sets;

    public private(set) string $requestId;

    public private(set) DateTimeImmutable $createdAt;

    public function __construct(
        public private(set) int $sequence,
        string $requestId,
        public private(set) ExerciseSnapshot $snapshot,
        WorkoutSetCollection $plannedSets,
        public private(set) bool $replaceInPlan,
        DateTimeImmutable $createdAt,
        public private(set) ?int $planRevisionAfter = null,
    ) {
        if (
            $sequence < 1
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $requestId) !== 1
            || ($planRevisionAfter !== null && ($planRevisionAfter < 0 || ! $replaceInPlan))
        ) {
            throw new InvalidWorkoutExerciseReplacement;
        }

        if ($plannedSets->isEmpty()) {
            throw new WorkoutExerciseHasNoSets($snapshot->exerciseId);
        }

        $this->requestId = strtolower($requestId);
        $this->sets = $plannedSets->copy();
        $this->createdAt = $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    /** @return non-empty-list<WorkoutSet> */
    public function plannedSets(): array
    {
        /** @var non-empty-list<WorkoutSet> $sets */
        $sets = $this->sets->copy()->all();

        return $sets;
    }

    public function matchesPayload(
        ExerciseId $exerciseId,
        bool $replaceInPlan,
        WorkoutSetCollection $sets,
    ): bool {
        return $this->snapshot->exerciseId->value === $exerciseId->value
            && $this->replaceInPlan === $replaceInPlan
            && $this->sets->equals($sets);
    }
}
