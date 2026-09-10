<?php

use App\WorkoutPlanning\Domain\Collections\PlannedExerciseCollection;
use App\WorkoutPlanning\Domain\Entities\PlannedExercise;
use App\WorkoutPlanning\Domain\Exceptions\ExerciseAlreadyPlanned;
use App\WorkoutPlanning\Domain\Exceptions\InvalidExerciseOrder;
use App\WorkoutPlanning\Domain\Exceptions\PlannedExerciseNotFound;
use App\WorkoutPlanning\Domain\Exceptions\TrainingProgramMustContainExercise;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use App\WorkoutPlanning\Domain\ValueObjects\ExercisePosition;
use App\WorkoutPlanning\Domain\ValueObjects\RepetitionsPerSet;
use App\WorkoutPlanning\Domain\ValueObjects\SetsCount;
use App\WorkoutPlanning\Domain\ValueObjects\WorkingWeight;

$plannedExercise = static fn (int $exerciseId, int $position): PlannedExercise => new PlannedExercise(
    new ExerciseId($exerciseId),
    new SetsCount(3),
    new RepetitionsPerSet(6),
    new WorkingWeight(100_000),
    new ExercisePosition($position),
);

it('keeps exercises ordered by their contiguous positions', function () use ($plannedExercise) {
    $exercises = new PlannedExerciseCollection(
        $plannedExercise(2, 2),
        $plannedExercise(1, 1),
    );

    expect(array_map(
        static fn (PlannedExercise $exercise): int => $exercise->exerciseId->value,
        $exercises->all(),
    ))->toBe([1, 2]);
});

it('rejects a duplicate exercise', function () use ($plannedExercise) {
    expect(fn () => new PlannedExerciseCollection(
        $plannedExercise(1, 1),
        $plannedExercise(1, 2),
    ))->toThrow(
        ExerciseAlreadyPlanned::class,
        'Упражнение 1 уже добавлено в программу тренировок.',
    );
});

it('rejects non-contiguous positions', function () use ($plannedExercise) {
    expect(fn () => new PlannedExerciseCollection(
        $plannedExercise(1, 1),
        $plannedExercise(2, 3),
    ))->toThrow(
        InvalidExerciseOrder::class,
        'Порядок должен содержать каждое упражнение программы ровно один раз.',
    );
});

it('appends an exercise at the next position', function () use ($plannedExercise) {
    $exercises = new PlannedExerciseCollection($plannedExercise(1, 1));

    $exercises->add($plannedExercise(2, 2));

    expect($exercises->count())->toBe(2);
    expect($exercises->get(new ExerciseId(2))->position->value)->toBe(2);
});

it('rejects an appended exercise with an unexpected position', function () use ($plannedExercise) {
    $exercises = new PlannedExerciseCollection($plannedExercise(1, 1));

    expect(fn () => $exercises->add($plannedExercise(2, 3)))
        ->toThrow(
            InvalidExerciseOrder::class,
            'Порядок должен содержать каждое упражнение программы ровно один раз.',
        );
});

it('rejects access to an exercise that is not planned', function () use ($plannedExercise) {
    $exercises = new PlannedExerciseCollection($plannedExercise(1, 1));

    expect(fn () => $exercises->get(new ExerciseId(2)))
        ->toThrow(
            PlannedExerciseNotFound::class,
            'Упражнение 2 отсутствует в программе тренировок.',
        );
});

it('does not remove the last exercise', function () use ($plannedExercise) {
    $exercises = new PlannedExerciseCollection($plannedExercise(1, 1));

    expect(fn () => $exercises->remove(new ExerciseId(1)))
        ->toThrow(
            TrainingProgramMustContainExercise::class,
            'Программа тренировок должна содержать хотя бы одно упражнение.',
        );
});

it('removes an exercise and closes the position gap', function () use ($plannedExercise) {
    $exercises = new PlannedExerciseCollection(
        $plannedExercise(1, 1),
        $plannedExercise(2, 2),
        $plannedExercise(3, 3),
    );

    $exercises->remove(new ExerciseId(2));

    expect(array_map(
        static fn (PlannedExercise $exercise): array => [
            $exercise->exerciseId->value,
            $exercise->position->value,
        ],
        $exercises->all(),
    ))->toBe([[1, 1], [3, 2]]);
});

it('reorders every planned exercise', function () use ($plannedExercise) {
    $exercises = new PlannedExerciseCollection(
        $plannedExercise(1, 1),
        $plannedExercise(2, 2),
        $plannedExercise(3, 3),
    );

    $exercises->reorder(
        new ExerciseId(3),
        new ExerciseId(1),
        new ExerciseId(2),
    );

    expect(array_map(
        static fn (PlannedExercise $exercise): int => $exercise->exerciseId->value,
        $exercises->all(),
    ))->toBe([3, 1, 2]);
});

it('rejects an incomplete or unknown exercise order', function (int $firstId, ?int $secondId) use ($plannedExercise) {
    $exercises = new PlannedExerciseCollection(
        $plannedExercise(1, 1),
        $plannedExercise(2, 2),
    );
    $orderedExerciseIds = [new ExerciseId($firstId)];

    if ($secondId !== null) {
        $orderedExerciseIds[] = new ExerciseId($secondId);
    }

    expect(fn () => $exercises->reorder(...$orderedExerciseIds))
        ->toThrow(InvalidExerciseOrder::class);
})->with([
    'missing exercise' => [1, null],
    'unknown exercise' => [1, 3],
    'duplicate exercise' => [1, 1],
]);
