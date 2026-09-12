<?php

use App\WorkoutExecution\Application\DTO\PlannedExerciseSnapshotData;
use App\WorkoutExecution\Application\DTO\TrainingProgramSnapshotData;
use App\WorkoutExecution\Application\Exceptions\InvalidTrainingProgramSnapshot;
use App\WorkoutExecution\Application\Exceptions\TrainingProgramNotFound;
use App\WorkoutExecution\Application\Factories\WorkoutSessionFactory;
use App\WorkoutExecution\Application\UseCases\StartWorkoutSession\StartWorkoutSession;
use App\WorkoutExecution\Application\UseCases\StartWorkoutSession\StartWorkoutSessionInput;
use App\WorkoutExecution\Domain\Exceptions\ActiveWorkoutSessionAlreadyExists;
use Tests\Support\WorkoutExecution\FrozenWorkoutClock;
use Tests\Support\WorkoutExecution\InMemoryTrainingProgramSnapshotProvider;
use Tests\Support\WorkoutExecution\InMemoryWorkoutSessionRepository;
use Tests\Support\WorkoutExecution\SynchronousWorkoutSessionMutationLock;
use Tests\Support\WorkoutExecution\WorkoutSessionFixture;

$programSnapshotData = static fn (): TrainingProgramSnapshotData => new TrainingProgramSnapshotData(
    trainingProgramId: 11,
    name: 'Грудь и трицепс',
    scheduledWeekday: 1,
    exercises: [
        new PlannedExerciseSnapshotData(10, 'Жим лежа', 3, 8, 90_000, 1),
        new PlannedExerciseSnapshotData(20, 'Разгибание на трицепс', 3, 12, 36_000, 2),
    ],
);

it('starts and saves a session from an immutable program snapshot', function () use ($programSnapshotData) {
    $startedAt = new DateTimeImmutable('2026-09-15 19:00:00', new DateTimeZone('Europe/Moscow'));
    $repository = new InMemoryWorkoutSessionRepository(nextId: 51);
    $provider = new InMemoryTrainingProgramSnapshotProvider(7, $programSnapshotData());
    $lock = new SynchronousWorkoutSessionMutationLock;
    $useCase = new StartWorkoutSession(
        $repository,
        $provider,
        new WorkoutSessionFactory,
        new FrozenWorkoutClock($startedAt),
        $lock,
    );

    $result = $useCase->handle(new StartWorkoutSessionInput(
        userId: 7,
        trainingProgramId: 11,
    ));

    expect([
        $result->id,
        $result->userId,
        $result->trainingProgramId,
        $result->programName,
        $result->scheduledWeekday,
        $result->status,
    ])->toBe([51, 7, 11, 'Грудь и трицепс', 1, 'in_progress']);
    expect($result->startedAt)->toEqual($startedAt);
    expect($result->completedAt)->toBeNull();
    expect($result->cancelledAt)->toBeNull();
    expect($result->exercises)->toHaveCount(2);
    expect([
        $result->exercises[0]->exerciseId,
        $result->exercises[0]->name,
        $result->exercises[0]->position,
        $result->exercises[0]->plannedSets,
        $result->exercises[0]->plannedRepetitionsPerSet,
        $result->exercises[0]->plannedWorkingWeightInGrams,
        $result->exercises[0]->status,
    ])->toBe([10, 'Жим лежа', 1, 3, 8, 90_000, 'pending']);
    expect(array_map(
        static fn ($set): array => [$set->position, $set->repetitions, $set->workingWeightInGrams],
        $result->exercises[0]->sets,
    ))->toBe([
        [1, 8, 90_000],
        [2, 8, 90_000],
        [3, 8, 90_000],
    ]);
    expect($provider->findCalls)->toBe(1);
    expect($repository->addCalls)->toBe(1);
    expect($lock->userIds)->toBe([7]);
});

it('returns the active session when the same program is started again', function () use ($programSnapshotData) {
    $repository = new InMemoryWorkoutSessionRepository(52, WorkoutSessionFixture::active());
    $provider = new InMemoryTrainingProgramSnapshotProvider(7, $programSnapshotData());
    $useCase = new StartWorkoutSession(
        $repository,
        $provider,
        new WorkoutSessionFactory,
        new FrozenWorkoutClock(new DateTimeImmutable('2026-09-16 20:00:00')),
        new SynchronousWorkoutSessionMutationLock,
    );

    $result = $useCase->handle(new StartWorkoutSessionInput(7, 11));

    expect($result->id)->toBe(51);
    expect($provider->findCalls)->toBe(0);
    expect($repository->addCalls)->toBe(0);
});

it('rejects starting another program while a session is active', function () use ($programSnapshotData) {
    $repository = new InMemoryWorkoutSessionRepository(52, WorkoutSessionFixture::active());
    $provider = new InMemoryTrainingProgramSnapshotProvider(7, $programSnapshotData());
    $useCase = new StartWorkoutSession(
        $repository,
        $provider,
        new WorkoutSessionFactory,
        new FrozenWorkoutClock(new DateTimeImmutable('2026-09-16 20:00:00')),
        new SynchronousWorkoutSessionMutationLock,
    );

    expect(fn () => $useCase->handle(new StartWorkoutSessionInput(7, 12)))
        ->toThrow(ActiveWorkoutSessionAlreadyExists::class);
    expect($provider->findCalls)->toBe(0);
    expect($repository->addCalls)->toBe(0);
});

it('does not expose a missing or another users program', function () {
    $repository = new InMemoryWorkoutSessionRepository(nextId: 51);
    $useCase = new StartWorkoutSession(
        $repository,
        new InMemoryTrainingProgramSnapshotProvider(8),
        new WorkoutSessionFactory,
        new FrozenWorkoutClock(new DateTimeImmutable('2026-09-16 20:00:00')),
        new SynchronousWorkoutSessionMutationLock,
    );

    expect(fn () => $useCase->handle(new StartWorkoutSessionInput(7, 11)))
        ->toThrow(TrainingProgramNotFound::class);
    expect($repository->addCalls)->toBe(0);
});

it('rejects a program snapshot without exercises', function () {
    $repository = new InMemoryWorkoutSessionRepository(nextId: 51);
    $program = new TrainingProgramSnapshotData(
        trainingProgramId: 11,
        name: 'Пустая программа',
        scheduledWeekday: 1,
        exercises: [],
    );
    $useCase = new StartWorkoutSession(
        $repository,
        new InMemoryTrainingProgramSnapshotProvider(7, $program),
        new WorkoutSessionFactory,
        new FrozenWorkoutClock(new DateTimeImmutable('2026-09-16 20:00:00')),
        new SynchronousWorkoutSessionMutationLock,
    );

    expect(fn () => $useCase->handle(new StartWorkoutSessionInput(7, 11)))
        ->toThrow(InvalidTrainingProgramSnapshot::class);
    expect($repository->addCalls)->toBe(0);
});
