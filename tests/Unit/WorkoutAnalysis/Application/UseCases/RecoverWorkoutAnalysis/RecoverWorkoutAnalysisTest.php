<?php

use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\UseCases\CalculateWorkoutDeviations\CalculateWorkoutDeviationsInput;
use App\WorkoutAnalysis\Application\UseCases\InitializeWorkoutAnalysis\InitializeWorkoutAnalysisInput;
use App\WorkoutAnalysis\Application\UseCases\RecoverWorkoutAnalysis\RecoverWorkoutAnalysisInput;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use Tests\Support\WorkoutAnalysis\AnalysisUseCases;
use Tests\Support\WorkoutAnalysis\InMemoryAnalysisEnvironment;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('does not initialize a missing analysis during recovery', function () {
    $env = new InMemoryAnalysisEnvironment;
    $app = new AnalysisUseCases($env);

    expect(fn () => $app->recover()->handle(new RecoverWorkoutAnalysisInput(7, 51)))
        ->toThrow(WorkoutAnalysisNotFound::class);

    expect($env->tasks)->toBe([]);
});

it('redispatches the same pending attempt after the delivery grace period', function () {
    $env = new InMemoryAnalysisEnvironment;
    $app = new AnalysisUseCases($env);
    $app->initialize()->handle(new InitializeWorkoutAnalysisInput(7, 51));
    $app->recover()->handle(new RecoverWorkoutAnalysisInput(7, 51));
    expect($env->tasks)->toHaveCount(1);
    $env->time = $env->time->modify('+1 minute');

    $dto = $app->recover()->handle(new RecoverWorkoutAnalysisInput(7, 51));

    expect($dto->attempts)->toHaveCount(1);
    expect($env->tasks)->toHaveCount(2);
    expect($env->tasks[1]->attemptNumber)->toBe(1);
});

it('recovers expired processing and invalidates its queued duplicate', function () {
    $env = new InMemoryAnalysisEnvironment;
    $app = new AnalysisUseCases($env);
    $app->initialize()->handle(new InitializeWorkoutAnalysisInput(7, 51));
    $env->execute(new UserId(7), function () use ($env): void {
        $analysis = $env->findForUser(new WorkoutAnalysisId(1), new UserId(7));
        $analysis?->startDeviationAttempt(1, $env->time, $env->time->modify('+2 minutes'));
        if ($analysis !== null) {
            $env->save($analysis);
        }
    });
    $app->recover()->handle(new RecoverWorkoutAnalysisInput(7, 51));
    expect($env->tasks)->toHaveCount(1);
    $env->time = $env->time->modify('+2 minutes');

    $dto = $app->recover()->handle(new RecoverWorkoutAnalysisInput(7, 51));

    expect($dto->status)->toBe('pending');
    expect($dto->attempts[0]->failureCode)->toBe('attempt_timed_out');
    expect($dto->attempts[1]->number)->toBe(2);
    expect($app->calculate()->handle(new CalculateWorkoutDeviationsInput(7, 1, 1))->status)->toBe('pending');
});

it('leaves a permanently failed stage for an explicit service retry', function () {
    $env = new InMemoryAnalysisEnvironment(Fixture::workout(Fixture::exercise([[2, PHP_INT_MAX]], [[1, 0]])));
    $app = new AnalysisUseCases($env);
    $app->initialize()->handle(new InitializeWorkoutAnalysisInput(7, 51));
    $app->calculate()->handle(new CalculateWorkoutDeviationsInput(7, 1, 1));
    $env->time = $env->time->modify('+1 day');

    $dto = $app->recover()->handle(new RecoverWorkoutAnalysisInput(7, 51));

    expect($dto->status)->toBe('failed');
    expect($env->tasks)->toHaveCount(1);
});
