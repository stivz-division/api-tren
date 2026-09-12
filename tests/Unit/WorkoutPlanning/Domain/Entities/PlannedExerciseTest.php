<?php

use App\WorkoutPlanning\Domain\ValueObjects\ExercisePosition;
use Tests\Support\WorkoutPlanning\PlannedExerciseFixture;

it('replaces all individual planned sets', function () {
    $exercise = PlannedExerciseFixture::exercise(exerciseId: 7);

    $exercise->replaceSets(PlannedExerciseFixture::sets(4, 8, 90_000));

    expect($exercise->plannedSets())->toHaveCount(4)
        ->and($exercise->plannedSets()[3]->position->value)->toBe(4)
        ->and($exercise->plannedSets()[3]->repetitions->value)->toBe(8)
        ->and($exercise->plannedSets()[3]->workingWeight->grams)->toBe(90_000);
});

it('changes its position without changing its identity', function () {
    $exercise = PlannedExerciseFixture::exercise(exerciseId: 7);

    $exercise->moveTo(new ExercisePosition(2));

    expect($exercise->exerciseId->value)->toBe(7);
    expect($exercise->position->value)->toBe(2);
});
