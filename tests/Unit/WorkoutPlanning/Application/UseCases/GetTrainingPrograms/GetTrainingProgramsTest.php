<?php

use App\WorkoutPlanning\Application\DTO\TrainingProgramDTO;
use App\WorkoutPlanning\Application\UseCases\GetTrainingPrograms\GetTrainingPrograms;
use App\WorkoutPlanning\Application\UseCases\GetTrainingPrograms\GetTrainingProgramsInput;
use App\WorkoutPlanning\Domain\Collections\PlannedExerciseCollection;
use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\ValueObjects\ProgramName;
use App\WorkoutPlanning\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use Tests\Support\WorkoutPlanning\InMemoryTrainingProgramRepository;
use Tests\Support\WorkoutPlanning\PlannedExerciseFixture;

$trainingProgram = static fn (
    int $id,
    int $userId,
    Weekday $weekday,
    string $name,
): TrainingProgram => TrainingProgram::restore(
    new TrainingProgramId($id),
    new UserId($userId),
    $weekday,
    new PlannedExerciseCollection(PlannedExerciseFixture::exercise($id)),
    new ProgramName($name),
);

it('returns only owned programs in weekday order', function () use ($trainingProgram): void {
    $repository = new InMemoryTrainingProgramRepository(
        4,
        $trainingProgram(1, 7, Weekday::Tuesday, 'Вторник'),
        $trainingProgram(2, 8, Weekday::Monday, 'Чужая программа'),
        $trainingProgram(3, 7, Weekday::Monday, 'Понедельник'),
    );
    $useCase = new GetTrainingPrograms($repository);

    $result = $useCase->handle(new GetTrainingProgramsInput(userId: 7));

    expect(array_map(
        static fn (TrainingProgramDTO $program): array => [
            $program->id,
            $program->userId,
            $program->weekday,
            $program->name,
        ],
        $result,
    ))->toBe([
        [3, 7, 1, 'Понедельник'],
        [1, 7, 2, 'Вторник'],
    ]);
});
