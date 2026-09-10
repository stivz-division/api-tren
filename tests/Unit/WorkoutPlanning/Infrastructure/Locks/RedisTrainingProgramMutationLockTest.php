<?php

use App\WorkoutPlanning\Application\Exceptions\TrainingProgramMutationInProgress;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use App\WorkoutPlanning\Infrastructure\Locks\RedisTrainingProgramMutationLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\LockTimeoutException;

it('serializes mutations for one user and returns the callback result', function (): void {
    $store = new ArrayStore;
    $mutationLock = new RedisTrainingProgramMutationLock($store, 10, 0);

    $firstResult = $mutationLock->execute(new UserId(7), static fn (): string => 'first');
    $secondResult = $mutationLock->execute(new UserId(7), static fn (): string => 'second');

    expect($firstResult)->toBe('first');
    expect($secondResult)->toBe('second');
});

it('reports an in-progress mutation when the user lock cannot be acquired', function (): void {
    $store = new ArrayStore;
    $heldLock = $store->lock('workout-planning:user:7', 10);
    $heldLock->get();
    $mutationLock = new RedisTrainingProgramMutationLock($store, 10, 0);

    try {
        expect(fn () => $mutationLock->execute(
            new UserId(7),
            static fn (): null => null,
        ))->toThrow(
            TrainingProgramMutationInProgress::class,
            'Изменение расписания тренировок уже выполняется.',
        );
    } finally {
        $heldLock->release();
    }
});

it('propagates callback exceptions without keeping the user lock', function (): void {
    $store = new ArrayStore;
    $mutationLock = new RedisTrainingProgramMutationLock($store, 10, 0);
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

it('rejects a non-positive lock duration', function (int $lockSeconds): void {
    expect(fn () => new RedisTrainingProgramMutationLock(
        new ArrayStore,
        $lockSeconds,
        0,
    ))->toThrow(
        InvalidArgumentException::class,
        'Время действия блокировки должно быть положительным.',
    );
})->with([0, -1]);

it('rejects a negative lock wait time', function (): void {
    expect(fn () => new RedisTrainingProgramMutationLock(
        new ArrayStore,
        10,
        -1,
    ))->toThrow(
        InvalidArgumentException::class,
        'Время ожидания блокировки не может быть отрицательным.',
    );
});
