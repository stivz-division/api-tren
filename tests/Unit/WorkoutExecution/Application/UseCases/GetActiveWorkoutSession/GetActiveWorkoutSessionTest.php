<?php

use App\WorkoutExecution\Application\UseCases\GetActiveWorkoutSession\GetActiveWorkoutSession;
use App\WorkoutExecution\Application\UseCases\GetActiveWorkoutSession\GetActiveWorkoutSessionInput;
use Tests\Support\WorkoutExecution\InMemoryWorkoutSessionRepository;
use Tests\Support\WorkoutExecution\WorkoutSessionFixture;

it('returns the users active workout session', function () {
    $useCase = new GetActiveWorkoutSession(new InMemoryWorkoutSessionRepository(
        52,
        WorkoutSessionFixture::active(),
    ));

    $result = $useCase->handle(new GetActiveWorkoutSessionInput(userId: 7));

    expect($result?->id)->toBe(51);
    expect($result?->trainingProgramId)->toBe(11);
});

it('returns null when the user has no active workout session', function () {
    $useCase = new GetActiveWorkoutSession(new InMemoryWorkoutSessionRepository(
        52,
        WorkoutSessionFixture::active(userId: 8),
    ));

    expect($useCase->handle(new GetActiveWorkoutSessionInput(userId: 7)))->toBeNull();
});
