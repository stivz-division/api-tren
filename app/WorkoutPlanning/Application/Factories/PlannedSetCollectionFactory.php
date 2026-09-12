<?php

namespace App\WorkoutPlanning\Application\Factories;

use App\WorkoutPlanning\Application\DTO\PlannedSetInput;
use App\WorkoutPlanning\Domain\Collections\PlannedSetCollection;
use App\WorkoutPlanning\Domain\Exceptions\PlannedExerciseMustContainSet;
use App\WorkoutPlanning\Domain\ValueObjects\PlannedSet;
use App\WorkoutPlanning\Domain\ValueObjects\Repetitions;
use App\WorkoutPlanning\Domain\ValueObjects\SetPosition;
use App\WorkoutPlanning\Domain\ValueObjects\WorkingWeight;

final readonly class PlannedSetCollectionFactory
{
    /** @param list<PlannedSetInput> $setInputs */
    public function create(array $setInputs): PlannedSetCollection
    {
        if ($setInputs === []) {
            throw new PlannedExerciseMustContainSet;
        }

        $sets = array_map(
            static fn (PlannedSetInput $input, int $position): PlannedSet => new PlannedSet(
                new SetPosition($position + 1),
                new Repetitions($input->repetitions),
                new WorkingWeight($input->workingWeightInGrams),
            ),
            $setInputs,
            array_keys($setInputs),
        );

        return new PlannedSetCollection($sets[0], ...array_slice($sets, 1));
    }
}
