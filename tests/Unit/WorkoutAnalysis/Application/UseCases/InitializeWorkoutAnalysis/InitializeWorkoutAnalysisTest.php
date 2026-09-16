<?php

use App\WorkoutAnalysis\Application\Exceptions\CompletedWorkoutNotFound;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutNotCompleted;
use App\WorkoutAnalysis\Application\Factories\CompletedWorkoutSnapshotFactory;
use App\WorkoutAnalysis\Application\UseCases\InitializeWorkoutAnalysis\InitializeWorkoutAnalysis;
use App\WorkoutAnalysis\Application\UseCases\InitializeWorkoutAnalysis\InitializeWorkoutAnalysisInput;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use Tests\Support\WorkoutAnalysis\InMemoryAnalysisEnvironment;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

$useCase = static fn (InMemoryAnalysisEnvironment $env): InitializeWorkoutAnalysis => new InitializeWorkoutAnalysis(
    $env, $env->provider(), new CompletedWorkoutSnapshotFactory, $env, $env, $env,
);

it('persists a pending analysis and dispatches after the outer commit', function () use ($useCase) {
    $env = new InMemoryAnalysisEnvironment;

    $env->execute(new UserId(7), function () use ($useCase, $env): void {
        $dto = $useCase($env)->handle(new InitializeWorkoutAnalysisInput(7, 51));
        expect($dto->status)->toBe('pending');
        expect($env->tasks)->toBe([]);
    });

    expect($env->tasks)->toHaveCount(1);
    expect($env->tasks[0]->analysisId)->toBe(1);
    expect($env->tasks[0]->attemptNumber)->toBe(1);
});

it('returns an existing analysis without rereading the source or dispatching a duplicate', function () use ($useCase) {
    $env = new InMemoryAnalysisEnvironment;
    $first = $useCase($env)->handle(new InitializeWorkoutAnalysisInput(7, 51));
    $env->source = null;

    $second = $useCase($env)->handle(new InitializeWorkoutAnalysisInput(7, 51));

    expect($second)->toEqual($first);
    expect($env->sourceReads)->toBe(1);
    expect($env->tasks)->toHaveCount(1);
});

it('retains pending state if dispatch fails after commit', function () use ($useCase) {
    $env = new InMemoryAnalysisEnvironment;
    $env->failDispatch = true;

    expect(fn () => $useCase($env)->handle(new InitializeWorkoutAnalysisInput(7, 51)))->toThrow(RuntimeException::class);

    expect($env->findForSession(new WorkoutSessionId(51), new UserId(7))?->deviations()->status()->value)->toBe('pending');
});

it('rolls back analysis and suppresses dispatch when the outer operation fails', function () use ($useCase) {
    $env = new InMemoryAnalysisEnvironment;

    expect(fn () => $env->execute(new UserId(7), function () use ($env, $useCase): void {
        $useCase($env)->handle(new InitializeWorkoutAnalysisInput(7, 51));
        throw new RuntimeException('Rollback.');
    }))->toThrow(RuntimeException::class);

    expect($env->findForSession(new WorkoutSessionId(51), new UserId(7)))->toBeNull();
    expect($env->tasks)->toBe([]);
});

it('does not initialize missing or foreign sessions', function (int $userId, int $sessionId) use ($useCase) {
    $env = new InMemoryAnalysisEnvironment;

    expect(fn () => $useCase($env)->handle(new InitializeWorkoutAnalysisInput($userId, $sessionId)))
        ->toThrow(CompletedWorkoutNotFound::class);
    expect($env->tasks)->toBe([]);
})->with(['foreign' => [8, 51], 'missing' => [7, 999]]);

it('rejects sessions that are not completed', function (string $status) use ($useCase) {
    $env = new InMemoryAnalysisEnvironment;
    $env->source = InMemoryAnalysisEnvironment::data(Fixture::workout(Fixture::exercise()), $status);

    expect(fn () => $useCase($env)->handle(new InitializeWorkoutAnalysisInput(7, 51)))->toThrow(WorkoutNotCompleted::class);
    expect($env->tasks)->toBe([]);
})->with(['in_progress', 'cancelled']);
