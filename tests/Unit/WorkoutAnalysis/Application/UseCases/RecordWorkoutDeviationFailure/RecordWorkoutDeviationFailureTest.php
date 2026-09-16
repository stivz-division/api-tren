<?php

use App\WorkoutAnalysis\Application\UseCases\CalculateWorkoutDeviations\CalculateWorkoutDeviationsInput;
use App\WorkoutAnalysis\Application\UseCases\GetWorkoutDeviationAnalysis\GetWorkoutDeviationAnalysisInput;
use App\WorkoutAnalysis\Application\UseCases\InitializeWorkoutAnalysis\InitializeWorkoutAnalysisInput;
use App\WorkoutAnalysis\Application\UseCases\RecordWorkoutDeviationFailure\RecordWorkoutDeviationFailureInput;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use Tests\Support\WorkoutAnalysis\AnalysisUseCases;
use Tests\Support\WorkoutAnalysis\InMemoryAnalysisEnvironment;

$start = static function (InMemoryAnalysisEnvironment $env, int $number): void {
    $env->execute(new UserId(7), function () use ($env, $number): void {
        $analysis = $env->findForUser(new WorkoutAnalysisId(1), new UserId(7));
        expect($analysis)->not->toBeNull();
        $analysis?->startDeviationAttempt($number, $env->time, $env->time->modify('+2 minutes'));
        if ($analysis !== null) {
            $env->save($analysis);
        }
    });
};

it('schedules a bounded retry after a transient worker failure and ignores a duplicate callback', function () use ($start) {
    $env = new InMemoryAnalysisEnvironment;
    $app = new AnalysisUseCases($env);
    $app->initialize()->handle(new InitializeWorkoutAnalysisInput(7, 51));
    $start($env, 1);
    $input = new RecordWorkoutDeviationFailureInput(7, 1, 1, AnalysisFailureCode::WorkerFailed);

    $dto = $app->failures()->handle($input);
    $again = $app->failures()->handle($input);

    expect($dto->status)->toBe('pending');
    expect($dto->attempts[0]->failureCode)->toBe('worker_failed');
    expect($dto->attempts[1]->scheduledAt)->toEqual($env->time->modify('+5 seconds'));
    expect($again)->toEqual($dto);
    expect($env->tasks)->toHaveCount(2);
    expect($env->tasks[1]->attemptNumber)->toBe(2);
    expect($app->calculate()->handle(new CalculateWorkoutDeviationsInput(7, 1, 2))->status)->toBe('pending');
});

it('becomes failed when the automatic attempt budget is exhausted', function () use ($start) {
    $env = new InMemoryAnalysisEnvironment;
    $app = new AnalysisUseCases($env);
    $app->initialize()->handle(new InitializeWorkoutAnalysisInput(7, 51));

    foreach ([1, 2, 3] as $number) {
        $start($env, $number);
        $app->failures()->handle(new RecordWorkoutDeviationFailureInput(7, 1, $number, AnalysisFailureCode::WorkerFailed));
        $env->time = $env->time->modify('+1 minute');
    }

    $dto = $app->get()->handle(new GetWorkoutDeviationAnalysisInput(7, 1));
    expect($dto->status)->toBe('failed');
    expect($dto->attempts)->toHaveCount(3);
    expect($env->tasks)->toHaveCount(3);
});
