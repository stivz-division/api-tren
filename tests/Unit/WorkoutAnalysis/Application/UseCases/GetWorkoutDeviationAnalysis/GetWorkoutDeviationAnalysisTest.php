<?php

use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\UseCases\GetWorkoutDeviationAnalysis\GetWorkoutDeviationAnalysisInput;
use App\WorkoutAnalysis\Application\UseCases\InitializeWorkoutAnalysis\InitializeWorkoutAnalysisInput;
use Tests\Support\WorkoutAnalysis\AnalysisUseCases;
use Tests\Support\WorkoutAnalysis\InMemoryAnalysisEnvironment;

it('reads a pending analysis without triggering work', function () {
    $env = new InMemoryAnalysisEnvironment;
    $app = new AnalysisUseCases($env);
    $app->initialize()->handle(new InitializeWorkoutAnalysisInput(7, 51));

    $dto = $app->get()->handle(new GetWorkoutDeviationAnalysisInput(7, 1));

    expect($dto->status)->toBe('pending');
    expect($dto->result)->toBeNull();
    expect($env->tasks)->toHaveCount(1);
});

it('hides foreign and missing analyses with the same error', function (int $user, int $id) {
    $env = new InMemoryAnalysisEnvironment;
    $app = new AnalysisUseCases($env);
    $app->initialize()->handle(new InitializeWorkoutAnalysisInput(7, 51));

    expect(fn () => $app->get()->handle(new GetWorkoutDeviationAnalysisInput($user, $id)))->toThrow(WorkoutAnalysisNotFound::class);
})->with(['foreign' => [8, 1], 'missing' => [7, 999]]);
