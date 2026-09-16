<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use App\WorkoutAnalysis\Domain\Enums\ExerciseCompletionStatus;
use InvalidArgumentException;

final readonly class WorkoutDeviationResult
{
    /** @var non-empty-list<ExerciseDeviation> */
    public private(set) array $exercises;

    public private(set) int $completedExercises;

    public private(set) int $skippedExercises;

    public private(set) MetricDeviation $sets;

    public private(set) MetricDeviation $repetitions;

    public private(set) MetricDeviation $volume;

    public function __construct(
        public private(set) CompletedWorkoutSnapshot $snapshot,
        ExerciseDeviation ...$exercises,
    ) {
        $exercises = array_values($exercises);
        $snapshots = $snapshot->exercises->all();

        if (count($exercises) !== count($snapshots) || $exercises === []) {
            throw new InvalidArgumentException('Результат должен содержать сравнение каждого упражнения снимка.');
        }

        $planned = LoadTotals::zero();
        $actual = LoadTotals::zero();
        $completed = 0;

        foreach ($exercises as $index => $exercise) {
            if ($exercise->snapshot != $snapshots[$index]) {
                throw new InvalidArgumentException('Сравнение упражнения не соответствует исходному снимку.');
            }

            $planned = $planned->plus($exercise->plannedTotals);
            $actual = $actual->plus($exercise->actualTotals);

            if ($exercise->snapshot->status === ExerciseCompletionStatus::Completed) {
                $completed++;
            }
        }

        $this->exercises = $exercises;
        $this->completedExercises = $completed;
        $this->skippedExercises = count($exercises) - $completed;
        $this->sets = new MetricDeviation($planned->sets, $actual->sets);
        $this->repetitions = new MetricDeviation($planned->repetitions, $actual->repetitions);
        $this->volume = new MetricDeviation($planned->volume, $actual->volume);
    }
}
