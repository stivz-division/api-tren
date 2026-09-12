<?php

use App\WorkoutExecution\Application\Exceptions\WorkoutSessionMutationInProgress;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Infrastructure\Locks\RedisWorkoutSessionMutationLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\LockTimeoutException;

it('serializes mutations for one user and returns the callback result', function (): void {
    $mutationLock = new RedisWorkoutSessionMutationLock(new ArrayStore, 10, 0);

    expect($mutationLock->execute(
        new UserId(7),
        static fn (): string => 'saved',
    ))->toBe('saved');
});

it('reports an in-progress mutation when the user lock cannot be acquired', function (): void {
    $store = new ArrayStore;
    $heldLock = $store->lock('workout-execution:user:7', 10);
    $heldLock->get();
    $mutationLock = new RedisWorkoutSessionMutationLock($store, 10, 0);

    try {
        expect(fn () => $mutationLock->execute(
            new UserId(7),
            static fn (): null => null,
        ))->toThrow(
            WorkoutSessionMutationInProgress::class,
            'Изменение выполняемой тренировки уже выполняется.',
        );
    } finally {
        $heldLock->release();
    }
});

it('releases the lock when the callback fails', function (): void {
    $store = new ArrayStore;
    $mutationLock = new RedisWorkoutSessionMutationLock($store, 10, 0);
    $callbackException = new LockTimeoutException;

    try {
        $mutationLock->execute(
            new UserId(7),
            static fn (): never => throw $callbackException,
        );
    } catch (Throwable $exception) {
        expect($exception)->toBe($callbackException);
    }

    expect($mutationLock->execute(
        new UserId(7),
        static fn (): string => 'released',
    ))->toBe('released');
});

it('rejects invalid lock timing', function (int $lockSeconds, int $waitSeconds): void {
    expect(fn () => new RedisWorkoutSessionMutationLock(
        new ArrayStore,
        $lockSeconds,
        $waitSeconds,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'zero duration' => [0, 0],
    'negative duration' => [-1, 0],
    'negative wait' => [10, -1],
]);
