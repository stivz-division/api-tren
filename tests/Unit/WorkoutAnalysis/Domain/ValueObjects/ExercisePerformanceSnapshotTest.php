<?php

use App\WorkoutAnalysis\Domain\Collections\SetSnapshotCollection;
use App\WorkoutAnalysis\Domain\Enums\ExerciseCompletionStatus;
use App\WorkoutAnalysis\Domain\ValueObjects\ExercisePerformanceSnapshot;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('rejects a completed exercise without performed sets', function () {
    expect(fn () => Fixture::exercise(actual: []))->toThrow(InvalidArgumentException::class);
});

it('rejects a skipped exercise with performed sets', function () {
    expect(fn () => Fixture::exercise(status: ExerciseCompletionStatus::Skipped))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an exercise without its historical plan', function () {
    $exercise = Fixture::exercise();

    expect(fn () => new ExercisePerformanceSnapshot(
        $exercise->exerciseId,
        $exercise->name,
        $exercise->position,
        $exercise->status,
        new SetSnapshotCollection,
        $exercise->actualSets,
    ))->toThrow(InvalidArgumentException::class);
});

it('does not accept unresolved exercise states in a completed snapshot', function () {
    expect(fn () => ExerciseCompletionStatus::from('pending'))->toThrow(ValueError::class);
});
