<?php

use App\WorkoutPlanning\Domain\Entities\PlannedExercise;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use App\WorkoutPlanning\Domain\ValueObjects\ExercisePosition;
use App\WorkoutPlanning\Domain\ValueObjects\RepetitionsPerSet;
use App\WorkoutPlanning\Domain\ValueObjects\SetsCount;
use App\WorkoutPlanning\Domain\ValueObjects\WorkingWeight;

it('changes the complete planned prescription', function () {
    $exercise = new PlannedExercise(
        new ExerciseId(7),
        new SetsCount(3),
        new RepetitionsPerSet(6),
        new WorkingWeight(100_000),
        new ExercisePosition(1),
    );

    $exercise->changePrescription(
        new SetsCount(4),
        new RepetitionsPerSet(8),
        new WorkingWeight(90_000),
    );

    expect($exercise->setsCount->value)->toBe(4);
    expect($exercise->repetitionsPerSet->value)->toBe(8);
    expect($exercise->workingWeight->grams)->toBe(90_000);
});

it('changes its position without changing its identity', function () {
    $exercise = new PlannedExercise(
        new ExerciseId(7),
        new SetsCount(3),
        new RepetitionsPerSet(6),
        new WorkingWeight(100_000),
        new ExercisePosition(1),
    );

    $exercise->moveTo(new ExercisePosition(2));

    expect($exercise->exerciseId->value)->toBe(7);
    expect($exercise->position->value)->toBe(2);
});
