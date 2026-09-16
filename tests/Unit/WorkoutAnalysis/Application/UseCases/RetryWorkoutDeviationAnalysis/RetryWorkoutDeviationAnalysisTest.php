<?php

use App\WorkoutAnalysis\Application\UseCases\CalculateWorkoutDeviations\CalculateWorkoutDeviationsInput;
use App\WorkoutAnalysis\Application\UseCases\InitializeWorkoutAnalysis\InitializeWorkoutAnalysisInput;
use App\WorkoutAnalysis\Application\UseCases\RetryWorkoutDeviationAnalysis\RetryWorkoutDeviationAnalysisInput;
use Tests\Support\WorkoutAnalysis\AnalysisUseCases;
use Tests\Support\WorkoutAnalysis\InMemoryAnalysisEnvironment;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('explicitly retries a failed stage once using the original snapshot', function () {
    $env = new InMemoryAnalysisEnvironment(Fixture::workout(Fixture::exercise([[2, PHP_INT_MAX]], [[1, 0]])));
    $app = new AnalysisUseCases($env);
    $app->initialize()->handle(new InitializeWorkoutAnalysisInput(7, 51));
    $app->calculate()->handle(new CalculateWorkoutDeviationsInput(7, 1, 1));
    $env->source = null;

    $dto = $app->retry()->handle(new RetryWorkoutDeviationAnalysisInput(7, 1));
    $again = $app->retry()->handle(new RetryWorkoutDeviationAnalysisInput(7, 1));

    expect($dto->status)->toBe('pending');
    expect($dto->attempts[1]->number)->toBe(2);
    expect($dto->attempts[1]->cycleAttempt)->toBe(1);
    expect($again)->toEqual($dto);
    expect($env->tasks)->toHaveCount(2);
    expect($env->sourceReads)->toBe(1);
});
