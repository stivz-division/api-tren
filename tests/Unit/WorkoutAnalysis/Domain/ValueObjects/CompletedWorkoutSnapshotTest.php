<?php

use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('preserves the workout identity ownership program and completion time', function () {
    $snapshot = Fixture::workout(Fixture::exercise());

    expect($snapshot->workoutSessionId->value)->toBe(51);
    expect($snapshot->userId->value)->toBe(7);
    expect($snapshot->trainingProgramId->value)->toBe(11);
    expect($snapshot->programName->value)->toBe('Грудь и трицепс');
    expect($snapshot->completedAt->format(DATE_ATOM))->toBe('2026-09-15T12:00:00+00:00');
});

it('rejects a completed workout without exercises', function () {
    expect(fn () => Fixture::workout())->toThrow(InvalidArgumentException::class);
});

it('keeps its exercise list independent of arrays returned to callers', function () {
    $snapshot = Fixture::workout(Fixture::exercise());

    $exercises = $snapshot->exercises->all();
    array_pop($exercises);

    expect($snapshot->exercises)->toHaveCount(1);
});
