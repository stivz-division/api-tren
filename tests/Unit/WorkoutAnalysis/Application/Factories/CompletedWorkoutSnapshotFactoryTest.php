<?php

use App\WorkoutAnalysis\Application\DTO\CompletedWorkoutData;
use App\WorkoutAnalysis\Application\DTO\ExercisePerformanceData;
use App\WorkoutAnalysis\Application\DTO\SetSnapshotData;
use App\WorkoutAnalysis\Application\Exceptions\CompletedWorkoutNotFound;
use App\WorkoutAnalysis\Application\Exceptions\InvalidCompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Application\Factories\CompletedWorkoutSnapshotFactory;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use Tests\Support\WorkoutAnalysis\InMemoryAnalysisEnvironment;

it('rejects a provider response with another identity', function (int $userId, int $sessionId) {
    $data = (new InMemoryAnalysisEnvironment)->source;
    expect($data)->not->toBeNull();

    expect(fn () => (new CompletedWorkoutSnapshotFactory)->create(
        $data ?? throw new LogicException,
        new UserId($userId),
        new WorkoutSessionId($sessionId),
    ))->toThrow(CompletedWorkoutNotFound::class);
})->with(['owner' => [8, 51], 'session' => [7, 99]]);

it('rejects a completed source without a completion time', function () {
    $data = new CompletedWorkoutData(51, 7, 11, 'Program', 'completed', null, []);

    expect(fn () => (new CompletedWorkoutSnapshotFactory)->create($data, new UserId(7), new WorkoutSessionId(51)))
        ->toThrow(InvalidCompletedWorkoutSnapshot::class);
});

it('rejects malformed exercise snapshots at the provider boundary', function (string $status, int $position, int $repetitions, int $weight) {
    $set = new SetSnapshotData($position, $repetitions, $weight);
    $data = new CompletedWorkoutData(51, 7, 11, 'Program', 'completed', new DateTimeImmutable('2026-09-17'), [
        new ExercisePerformanceData(10, 'Exercise', 1, $status, [$set], [$set]),
    ]);

    expect(fn () => (new CompletedWorkoutSnapshotFactory)->create($data, new UserId(7), new WorkoutSessionId(51)))
        ->toThrow(InvalidCompletedWorkoutSnapshot::class);
})->with([
    'unresolved' => ['pending', 1, 10, 0],
    'skipped with actual sets' => ['skipped', 1, 10, 0],
    'missing first position' => ['completed', 2, 10, 0],
    'zero repetitions' => ['completed', 1, 0, 0],
    'negative weight' => ['completed', 1, 10, -1],
]);

it('rejects an empty completed workout', function () {
    $data = new CompletedWorkoutData(51, 7, 11, 'Program', 'completed', new DateTimeImmutable('2026-09-17'), []);

    expect(fn () => (new CompletedWorkoutSnapshotFactory)->create($data, new UserId(7), new WorkoutSessionId(51)))
        ->toThrow(InvalidCompletedWorkoutSnapshot::class);
});
