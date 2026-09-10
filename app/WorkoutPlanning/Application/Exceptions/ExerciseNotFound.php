<?php

namespace App\WorkoutPlanning\Application\Exceptions;

use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use RuntimeException;

final class ExerciseNotFound extends RuntimeException
{
    /** @param non-empty-list<ExerciseId> $exerciseIds */
    public function __construct(public private(set) readonly array $exerciseIds)
    {
        parent::__construct(sprintf(
            'Упражнения не найдены: %s.',
            implode(', ', array_map(
                static fn (ExerciseId $exerciseId): int => $exerciseId->value,
                $exerciseIds,
            )),
        ));
    }
}
