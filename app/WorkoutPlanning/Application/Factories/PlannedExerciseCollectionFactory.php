<?php

namespace App\WorkoutPlanning\Application\Factories;

use App\WorkoutPlanning\Application\DTO\PlannedExerciseInput;
use App\WorkoutPlanning\Application\Exceptions\ExerciseNotFound;
use App\WorkoutPlanning\Application\Gateways\ExerciseCatalog;
use App\WorkoutPlanning\Domain\Collections\PlannedExerciseCollection;
use App\WorkoutPlanning\Domain\Entities\PlannedExercise;
use App\WorkoutPlanning\Domain\Exceptions\TrainingProgramMustContainExercise;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use App\WorkoutPlanning\Domain\ValueObjects\ExercisePosition;
use App\WorkoutPlanning\Domain\ValueObjects\RepetitionsPerSet;
use App\WorkoutPlanning\Domain\ValueObjects\SetsCount;
use App\WorkoutPlanning\Domain\ValueObjects\WorkingWeight;

final readonly class PlannedExerciseCollectionFactory
{
    public function __construct(
        private ExerciseCatalog $exerciseCatalog,
    ) {}

    /** @param list<PlannedExerciseInput> $exerciseInputs */
    public function create(array $exerciseInputs): PlannedExerciseCollection
    {
        if ($exerciseInputs === []) {
            throw new TrainingProgramMustContainExercise;
        }

        $exerciseIds = array_map(
            static fn (PlannedExerciseInput $input): ExerciseId => new ExerciseId($input->exerciseId),
            $exerciseInputs,
        );

        $missingExerciseIds = $this->exerciseCatalog->findMissing($exerciseIds);

        if ($missingExerciseIds !== []) {
            throw new ExerciseNotFound($missingExerciseIds);
        }

        $plannedExercises = array_map(
            static fn (PlannedExerciseInput $input, int $position): PlannedExercise => new PlannedExercise(
                new ExerciseId($input->exerciseId),
                new SetsCount($input->sets),
                new RepetitionsPerSet($input->repetitionsPerSet),
                new WorkingWeight($input->workingWeightInGrams),
                new ExercisePosition($position + 1),
            ),
            $exerciseInputs,
            array_keys($exerciseInputs),
        );

        return new PlannedExerciseCollection(
            $plannedExercises[0],
            ...array_slice($plannedExercises, 1),
        );
    }
}
