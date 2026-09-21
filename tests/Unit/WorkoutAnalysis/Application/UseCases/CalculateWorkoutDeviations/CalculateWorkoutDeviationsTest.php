<?php

use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\UseCases\CalculateWorkoutDeviations\CalculateWorkoutDeviations;
use App\WorkoutAnalysis\Application\UseCases\CalculateWorkoutDeviations\CalculateWorkoutDeviationsInput;
use App\WorkoutAnalysis\Application\UseCases\InitializeWorkoutAnalysis\InitializeWorkoutAnalysisInput;
use App\WorkoutAnalysis\Application\UseCases\RecoverWorkoutAnalysis\RecoverWorkoutAnalysisInput;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use Tests\Support\WorkoutAnalysis\AnalysisUseCases;
use Tests\Support\WorkoutAnalysis\InMemoryAnalysisEnvironment;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('calculates from the stored snapshot and returns a complete transport result', function () {
    $env = new InMemoryAnalysisEnvironment(Fixture::workout(Fixture::exercise([[10, 50_000], [8, 60_000]], [[10, 50_000]])));
    $app = new AnalysisUseCases($env);
    $app->initialize()->handle(new InitializeWorkoutAnalysisInput(7, 51));
    $env->source = null;

    $dto = $app->calculate()->handle(new CalculateWorkoutDeviationsInput(7, 1, 1));

    expect($dto->status)->toBe('completed');
    expect($dto->result?->sets->difference)->toBe(-1);
    expect($dto->result?->volume->actual)->toBe(500_000);
    expect($dto->result?->exercises[0]->snapshot->plannedSets)->toHaveCount(2);
    expect($dto->result?->exercises[0]->setComparisons[1]->actual)->toBeNull();
    expect($dto->result?->exercises[0]->setComparisons[1]->volume)->toBeNull();
    expect($dto->result?->exercises[0]->planFulfilled)->toBeFalse();
    expect($dto->attempts[0]->startedAt)->toEqual($env->time);
    expect($env->sourceReads)->toBe(1);
});

it('does not overwrite completed calculations on redelivery', function () {
    $env = new InMemoryAnalysisEnvironment;
    $app = new AnalysisUseCases($env);
    $app->initialize()->handle(new InitializeWorkoutAnalysisInput(7, 51));
    $first = $app->calculate()->handle(new CalculateWorkoutDeviationsInput(7, 1, 1));
    $saved = $env->saves;

    $second = $app->calculate()->handle(new CalculateWorkoutDeviationsInput(7, 1, 1));

    expect($second)->toEqual($first);
    expect($env->saves)->toBe($saved);
});

it('ignores a job for a different attempt', function () {
    $env = new InMemoryAnalysisEnvironment;
    $app = new AnalysisUseCases($env);
    $app->initialize()->handle(new InitializeWorkoutAnalysisInput(7, 51));

    $dto = $app->calculate()->handle(new CalculateWorkoutDeviationsInput(7, 1, 2));

    expect($dto->status)->toBe('pending');
    expect($dto->result)->toBeNull();
});

it('records arithmetic overflow as a permanent failure without exposing an exception message', function () {
    $env = new InMemoryAnalysisEnvironment(Fixture::workout(Fixture::exercise([[2, PHP_INT_MAX]], [[1, 0]])));
    $app = new AnalysisUseCases($env);
    $app->initialize()->handle(new InitializeWorkoutAnalysisInput(7, 51));

    $dto = $app->calculate()->handle(new CalculateWorkoutDeviationsInput(7, 1, 1));

    expect($dto->status)->toBe('failed');
    expect($dto->attempts[0]->failureCode)->toBe('arithmetic_overflow');
    expect($dto->result)->toBeNull();
    expect($env->tasks)->toHaveCount(1);
});

it('does not calculate an analysis belonging to another user', function () {
    $env = new InMemoryAnalysisEnvironment;
    $app = new AnalysisUseCases($env);
    $app->initialize()->handle(new InitializeWorkoutAnalysisInput(7, 51));

    expect(fn () => $app->calculate()->handle(new CalculateWorkoutDeviationsInput(8, 1, 1)))
        ->toThrow(WorkoutAnalysisNotFound::class);
});

it('rejects a late application result after recovery has replaced its attempt', function () {
    $env = new InMemoryAnalysisEnvironment;
    $app = new AnalysisUseCases($env);
    $app->initialize()->handle(new InitializeWorkoutAnalysisInput(7, 51));
    $transaction = new class($env, $app) implements AnalysisTransaction
    {
        public function __construct(private InMemoryAnalysisEnvironment $env, private AnalysisUseCases $app) {}

        public function execute(UserId $userId, Closure $callback): mixed
        {
            $result = $this->env->execute($userId, $callback);
            if ($result instanceof CompletedWorkoutSnapshot) {
                expect($this->env->depth)->toBe(0);
                $this->env->time = $this->env->time->modify('+2 minutes');
                $this->app->recover()->handle(new RecoverWorkoutAnalysisInput(7, 51));
            }

            return $result;
        }

        public function afterCommit(Closure $callback): void
        {
            $this->env->afterCommit($callback);
        }
    };
    $calculate = new CalculateWorkoutDeviations(
        $env, new WorkoutDeviationCalculator, $env, $transaction, $app->policy, $app->failures(), $env->aiScheduler(),
    );

    $dto = $calculate->handle(new CalculateWorkoutDeviationsInput(7, 1, 1));

    expect($dto->status)->toBe('pending');
    expect($dto->attempts[1]->number)->toBe(2);
    expect($dto->result)->toBeNull();
});
