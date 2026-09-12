<?php

namespace App\WorkoutExecution\Application\Factories;

use App\WorkoutExecution\Application\DTO\WorkoutSetInput;
use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\ValueObjects\Repetitions;
use App\WorkoutExecution\Domain\ValueObjects\SetPosition;
use App\WorkoutExecution\Domain\ValueObjects\WorkingWeight;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSet;

final readonly class WorkoutSetCollectionFactory
{
    /** @param list<WorkoutSetInput> $setInputs */
    public function create(array $setInputs): WorkoutSetCollection
    {
        $sets = array_map(
            static fn (WorkoutSetInput $input, int $position): WorkoutSet => new WorkoutSet(
                new SetPosition($position + 1),
                new Repetitions($input->repetitions),
                new WorkingWeight($input->workingWeightInGrams),
            ),
            $setInputs,
            array_keys($setInputs),
        );

        return new WorkoutSetCollection(...$sets);
    }
}
