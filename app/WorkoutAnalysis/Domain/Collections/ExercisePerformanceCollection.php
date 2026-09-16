<?php

namespace App\WorkoutAnalysis\Domain\Collections;

use App\WorkoutAnalysis\Domain\ValueObjects\ExercisePerformanceSnapshot;
use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, ExercisePerformanceSnapshot> */
final readonly class ExercisePerformanceCollection implements Countable, IteratorAggregate
{
    /** @var list<ExercisePerformanceSnapshot> */
    private array $exercises;

    public function __construct(ExercisePerformanceSnapshot ...$exercises)
    {
        $exercises = array_values($exercises);
        usort($exercises, static fn (ExercisePerformanceSnapshot $left, ExercisePerformanceSnapshot $right): int => $left->position->value <=> $right->position->value);

        $exerciseIds = [];

        foreach ($exercises as $index => $exercise) {
            if ($exercise->position->value !== $index + 1) {
                throw new InvalidArgumentException('Позиции упражнений должны быть уникальными и непрерывными, начиная с 1.');
            }

            if (isset($exerciseIds[$exercise->exerciseId->value])) {
                throw new InvalidArgumentException('Упражнение не может повторяться в снимке тренировки.');
            }

            $exerciseIds[$exercise->exerciseId->value] = true;
        }

        $this->exercises = $exercises;
    }

    /** @return list<ExercisePerformanceSnapshot> */
    public function all(): array
    {
        return $this->exercises;
    }

    public function count(): int
    {
        return count($this->exercises);
    }

    /** @return Traversable<int, ExercisePerformanceSnapshot> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->exercises);
    }
}
