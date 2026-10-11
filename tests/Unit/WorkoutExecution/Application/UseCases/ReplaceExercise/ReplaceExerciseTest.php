<?php

use App\WorkoutExecution\Application\DTO\WorkoutSetInput;
use App\WorkoutExecution\Application\Exceptions\WorkoutReplacementCandidateNotFound;
use App\WorkoutExecution\Application\Exceptions\WorkoutSessionNotFound;
use App\WorkoutExecution\Application\Factories\WorkoutSetCollectionFactory;
use App\WorkoutExecution\Application\Gateways\WorkoutReplacementCatalog;
use App\WorkoutExecution\Application\Gateways\WorkoutReplacementPlanGateway;
use App\WorkoutExecution\Application\UseCases\ReplaceExercise\ReplaceExercise;
use App\WorkoutExecution\Application\UseCases\ReplaceExercise\ReplaceExerciseInput;
use App\WorkoutExecution\Domain\Exceptions\WorkoutReplacementRequestConflict;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseName;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;
use Mockery\MockInterface;
use Tests\Support\WorkoutExecution\FrozenWorkoutClock;
use Tests\Support\WorkoutExecution\InMemoryWorkoutSessionRepository;
use Tests\Support\WorkoutExecution\SynchronousWorkoutSessionMutationLock;
use Tests\Support\WorkoutExecution\WorkoutSessionFixture;

/** @return WorkoutReplacementCatalog&MockInterface */
function replacementCatalogMock(): WorkoutReplacementCatalog
{
    $mock = Mockery::mock(WorkoutReplacementCatalog::class);
    if (! $mock instanceof WorkoutReplacementCatalog) {
        throw new LogicException('Invalid mock.');
    }

    return $mock;
}

/** @return WorkoutReplacementPlanGateway&MockInterface */
function replacementPlanMock(): WorkoutReplacementPlanGateway
{
    $mock = Mockery::mock(WorkoutReplacementPlanGateway::class);
    if (! $mock instanceof WorkoutReplacementPlanGateway) {
        throw new LogicException('Invalid mock.');
    }

    return $mock;
}

$input = static fn (bool $permanent = false, int $userId = 7, int $candidate = 30): ReplaceExerciseInput => new ReplaceExerciseInput(
    $userId,
    51,
    10, '00000000-0000-4000-8000-000000000001',
    $candidate,
    [new WorkoutSetInput(10, 25000)],
    $permanent,
);

$useCase = static function (
    InMemoryWorkoutSessionRepository $repository,
    WorkoutReplacementCatalog $catalog,
    WorkoutReplacementPlanGateway $gateway,
    ?SynchronousWorkoutSessionMutationLock $lock = null,
): ReplaceExercise {
    return new ReplaceExercise(
        $repository,
        new WorkoutSetCollectionFactory,
        $lock ?? new SynchronousWorkoutSessionMutationLock,
        $catalog,
        $gateway,
        new FrozenWorkoutClock(new DateTimeImmutable('2026-09-21T12:00:00Z')),
    );
};

it('saves replacement and only invokes the plan gateway for the permanent mode', function (bool $permanent) use ($input, $useCase): void {
    $repository = new InMemoryWorkoutSessionRepository(52, WorkoutSessionFixture::active());
    $catalog = replacementCatalogMock();
    $catalog->shouldReceive('findName')->once()->andReturn(new ExerciseName('Замена'));
    $gateway = replacementPlanMock();
    $gateway->shouldReceive('replace')->times($permanent ? 1 : 0)->andReturn(3);
    $lock = new SynchronousWorkoutSessionMutationLock;
    $result = $useCase($repository, $catalog, $gateway, $lock)->handle($input($permanent));
    expect($result->exercises)->toHaveCount(2);
    expect($result->exercises[0]->sets[0]->workingWeightInGrams)->toBe(25000);
    expect($repository->saveCalls)->toBe(1);
    expect($lock->userIds)->toBe([7]);
    $exercise = ($repository->find(new WorkoutSessionId(51)) ?? throw new LogicException('Missing session.'))->workoutExercises()[0];
    expect($exercise->effectiveSnapshot()->exerciseId->value)->toBe(30);
    expect($exercise->planBaselineRevision())->toBe($permanent ? 3 : null);
})->with([false, true]);

it('checks ownership before accessing catalog or plan', function () use ($input, $useCase): void {
    $repository = new InMemoryWorkoutSessionRepository(52, WorkoutSessionFixture::active());
    $catalog = replacementCatalogMock();
    $catalog->shouldNotReceive('findName');
    $gateway = replacementPlanMock();
    $gateway->shouldNotReceive('replace');
    expect(fn () => $useCase($repository, $catalog, $gateway)->handle($input(true, 8)))->toThrow(WorkoutSessionNotFound::class);
    expect($repository->saveCalls)->toBe(0);
});

it('rejects missing candidate without saving anything', function () use ($input, $useCase): void {
    $repository = new InMemoryWorkoutSessionRepository(52, WorkoutSessionFixture::active());
    $catalog = replacementCatalogMock();
    $catalog->shouldReceive('findName')->once()->andReturnNull();
    $gateway = replacementPlanMock();
    $gateway->shouldNotReceive('replace');
    expect(fn () => $useCase($repository, $catalog, $gateway)->handle($input(true)))->toThrow(WorkoutReplacementCandidateNotFound::class);
    expect($repository->saveCalls)->toBe(0);
});

it('replays after completion without catalog or plan access and preserves current progress', function () use ($input, $useCase): void {
    $repository = new InMemoryWorkoutSessionRepository(52, WorkoutSessionFixture::active());
    $catalog = replacementCatalogMock();
    $catalog->shouldReceive('findName')->once()->andReturn(new ExerciseName('Замена'));
    $gateway = replacementPlanMock();
    $gateway->shouldNotReceive('replace');
    $handler = $useCase($repository, $catalog, $gateway);
    $handler->handle($input());
    $session = $repository->find(new WorkoutSessionId(51)) ?? throw new LogicException('Missing session.');
    $session->completeExercise(new ExerciseId(10), WorkoutSessionFixture::sets(1, 12, 35000));
    $session->skipExercise(new ExerciseId(20));
    $session->complete(new DateTimeImmutable('2026-09-21T13:00:00Z'));
    $repository->save($session);
    $result = $handler->handle($input());
    expect($result->status)->toBe('completed');
    expect($result->exercises[0]->sets[0]->repetitions)->toBe(12);
    expect($repository->saveCalls)->toBe(2);
    expect(fn () => $handler->handle($input(false, 7, 31)))->toThrow(WorkoutReplacementRequestConflict::class);
});
