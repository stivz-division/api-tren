<?php

use App\WorkoutPlanning\Application\Exceptions\TrainingProgramNotFound;
use App\WorkoutPlanning\Application\UseCases\GetTrainingProgramForWeekday\GetTrainingProgramForWeekday;
use App\WorkoutPlanning\Application\UseCases\GetTrainingProgramForWeekday\GetTrainingProgramForWeekdayInput;
use App\WorkoutPlanning\Domain\Collections\PlannedExerciseCollection;
use App\WorkoutPlanning\Domain\Entities\PlannedExercise;
use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\Exceptions\InvalidWeekday;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use App\WorkoutPlanning\Domain\ValueObjects\ExercisePosition;
use App\WorkoutPlanning\Domain\ValueObjects\ProgramName;
use App\WorkoutPlanning\Domain\ValueObjects\RepetitionsPerSet;
use App\WorkoutPlanning\Domain\ValueObjects\SetsCount;
use App\WorkoutPlanning\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use App\WorkoutPlanning\Domain\ValueObjects\WorkingWeight;
use Tests\Support\WorkoutPlanning\InMemoryTrainingProgramRepository;

$mondayProgram = static fn (): TrainingProgram => TrainingProgram::restore(
    new TrainingProgramId(5),
    new UserId(7),
    Weekday::Monday,
    new PlannedExerciseCollection(new PlannedExercise(
        new ExerciseId(10),
        new SetsCount(3),
        new RepetitionsPerSet(6),
        new WorkingWeight(100_000),
        new ExercisePosition(1),
    )),
    ProgramName::default(),
);

it('returns an owned program for an explicit weekday', function () use ($mondayProgram) {
    $tuesdayProgram = TrainingProgram::restore(
        new TrainingProgramId(6),
        new UserId(7),
        Weekday::Tuesday,
        new PlannedExerciseCollection(new PlannedExercise(
            new ExerciseId(20),
            new SetsCount(4),
            new RepetitionsPerSet(8),
            new WorkingWeight(50_000),
            new ExercisePosition(1),
        )),
        ProgramName::default(),
    );
    $repository = new InMemoryTrainingProgramRepository(7, $mondayProgram(), $tuesdayProgram);
    $useCase = new GetTrainingProgramForWeekday($repository);

    $result = $useCase->handle(new GetTrainingProgramForWeekdayInput(userId: 7, weekday: 2));

    expect([
        $result->id,
        $result->userId,
        $result->weekday,
        $result->name,
        $result->exercises[0]->exerciseId,
    ])->toBe([6, 7, 2, 'Тренировка', 20]);
});

it('does not return another users program', function () use ($mondayProgram) {
    $repository = new InMemoryTrainingProgramRepository(6, $mondayProgram());
    $useCase = new GetTrainingProgramForWeekday($repository);

    expect(fn () => $useCase->handle(new GetTrainingProgramForWeekdayInput(
        userId: 8,
        weekday: 1,
    )))->toThrow(TrainingProgramNotFound::class);
});

it('rejects an unsupported weekday with a Russian domain error', function () {
    $repository = new InMemoryTrainingProgramRepository;
    $useCase = new GetTrainingProgramForWeekday($repository);

    expect(fn () => $useCase->handle(new GetTrainingProgramForWeekdayInput(
        userId: 7,
        weekday: 8,
    )))->toThrow(
        InvalidWeekday::class,
        'День недели должен быть числом от 1 до 7.',
    );
});
