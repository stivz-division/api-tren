<?php

use App\WorkoutPlanning\Domain\Collections\PlannedExerciseCollection;
use App\WorkoutPlanning\Domain\Entities\PlannedExercise;
use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\Exceptions\TrainingProgramAlreadyExists;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use App\WorkoutPlanning\Domain\ValueObjects\ExercisePosition;
use App\WorkoutPlanning\Domain\ValueObjects\ProgramName;
use App\WorkoutPlanning\Domain\ValueObjects\RepetitionsPerSet;
use App\WorkoutPlanning\Domain\ValueObjects\SetsCount;
use App\WorkoutPlanning\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use App\WorkoutPlanning\Domain\ValueObjects\WorkingWeight;
use Tests\Support\WorkoutPlanning\InMemoryTrainingProgramRepository;

$plannedExercises = static fn (): PlannedExerciseCollection => new PlannedExerciseCollection(
    new PlannedExercise(
        new ExerciseId(10),
        new SetsCount(3),
        new RepetitionsPerSet(6),
        new WorkingWeight(100_000),
        new ExercisePosition(1),
    ),
);

$persistedProgram = static fn (
    int $id,
    int $userId,
    Weekday $weekday,
): TrainingProgram => TrainingProgram::restore(
    new TrainingProgramId($id),
    new UserId($userId),
    $weekday,
    $plannedExercises(),
    ProgramName::default(),
);

it('rejects adding an already persisted aggregate without changing state', function () use ($persistedProgram): void {
    $repository = new InMemoryTrainingProgramRepository;
    $program = $persistedProgram(10, 7, Weekday::Monday);

    expect(fn () => $repository->add($program))->toThrow(
        LogicException::class,
        'Нельзя добавить уже сохранённую программу тренировок.',
    );
    expect($repository->addCalls)->toBe(0)
        ->and($repository->find(new TrainingProgramId(10)))->toBeNull();
});

it('assigns an identity after the largest seeded identity', function () use ($persistedProgram, $plannedExercises): void {
    $seededProgram = $persistedProgram(4, 7, Weekday::Monday);
    $repository = new InMemoryTrainingProgramRepository(1, $seededProgram);
    $newProgram = TrainingProgram::create(
        new UserId(8),
        Weekday::Tuesday,
        $plannedExercises(),
    );

    $addedProgram = $repository->add($newProgram);

    expect($addedProgram->id?->value)->toBe(5)
        ->and($repository->find(new TrainingProgramId(4)))->not->toBeNull()
        ->and($repository->find(new TrainingProgramId(5)))->not->toBeNull();
});

it('rejects duplicate seeded identities', function () use ($persistedProgram): void {
    $firstProgram = $persistedProgram(1, 7, Weekday::Monday);
    $secondProgram = $persistedProgram(1, 8, Weekday::Tuesday);

    expect(fn () => new InMemoryTrainingProgramRepository(
        1,
        $firstProgram,
        $secondProgram,
    ))->toThrow(
        LogicException::class,
        'Тестовый репозиторий не может содержать повторяющийся идентификатор программы тренировок.',
    );
});

it('rejects duplicate user and weekday among seeded programs', function () use ($persistedProgram): void {
    $firstProgram = $persistedProgram(1, 7, Weekday::Monday);
    $secondProgram = $persistedProgram(2, 7, Weekday::Monday);

    expect(fn () => new InMemoryTrainingProgramRepository(
        1,
        $firstProgram,
        $secondProgram,
    ))->toThrow(TrainingProgramAlreadyExists::class);
});

it('rejects a non-positive next identity', function (int $nextId): void {
    expect(fn () => new InMemoryTrainingProgramRepository($nextId))->toThrow(
        InvalidArgumentException::class,
        'Следующий идентификатор программы тренировок должен быть положительным.',
    );
})->with([0, -1]);
