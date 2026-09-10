<?php

use App\WorkoutPlanning\Application\Exceptions\TrainingProgramNotFound;
use App\WorkoutPlanning\Application\UseCases\DeleteTrainingProgram\DeleteTrainingProgram;
use App\WorkoutPlanning\Application\UseCases\DeleteTrainingProgram\DeleteTrainingProgramInput;
use App\WorkoutPlanning\Domain\Collections\PlannedExerciseCollection;
use App\WorkoutPlanning\Domain\Entities\PlannedExercise;
use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use App\WorkoutPlanning\Domain\ValueObjects\ExercisePosition;
use App\WorkoutPlanning\Domain\ValueObjects\ProgramName;
use App\WorkoutPlanning\Domain\ValueObjects\RepetitionsPerSet;
use App\WorkoutPlanning\Domain\ValueObjects\SetsCount;
use App\WorkoutPlanning\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use App\WorkoutPlanning\Domain\ValueObjects\WorkingWeight;
use Tests\Support\WorkoutPlanning\InMemoryTrainingProgramRepository;
use Tests\Support\WorkoutPlanning\SynchronousTrainingProgramMutationLock;

$trainingProgramForDeletion = static fn (): TrainingProgram => TrainingProgram::restore(
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

it('deletes an owned program', function () use ($trainingProgramForDeletion) {
    $repository = new InMemoryTrainingProgramRepository(6, $trainingProgramForDeletion());
    $lock = new SynchronousTrainingProgramMutationLock;
    $useCase = new DeleteTrainingProgram($repository, $lock);

    $useCase->handle(new DeleteTrainingProgramInput(userId: 7, trainingProgramId: 5));

    expect($repository->find(new TrainingProgramId(5)))->toBeNull();
    expect($repository->deleteCalls)->toBe(1);
    expect($lock->userIds)->toBe([7]);
});

it('does not delete another users program', function () use ($trainingProgramForDeletion) {
    $repository = new InMemoryTrainingProgramRepository(6, $trainingProgramForDeletion());
    $useCase = new DeleteTrainingProgram(
        $repository,
        new SynchronousTrainingProgramMutationLock,
    );

    expect(fn () => $useCase->handle(new DeleteTrainingProgramInput(
        userId: 8,
        trainingProgramId: 5,
    )))->toThrow(TrainingProgramNotFound::class);
    expect($repository->find(new TrainingProgramId(5)))->not->toBeNull();
    expect($repository->deleteCalls)->toBe(0);
});
