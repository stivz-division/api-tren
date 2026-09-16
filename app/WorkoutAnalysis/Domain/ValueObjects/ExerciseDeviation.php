<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use App\WorkoutAnalysis\Domain\Enums\ExerciseCompletionStatus;

final readonly class ExerciseDeviation
{
    /** @var non-empty-list<SetComparison> */
    public private(set) array $setComparisons;

    public private(set) LoadTotals $plannedTotals;

    public private(set) LoadTotals $actualTotals;

    public private(set) MetricDeviation $sets;

    public private(set) MetricDeviation $repetitions;

    public private(set) MetricDeviation $volume;

    public private(set) bool $planFulfilled;

    public function __construct(public private(set) ExercisePerformanceSnapshot $snapshot)
    {
        $this->plannedTotals = LoadTotals::fromSets($snapshot->plannedSets);
        $this->actualTotals = LoadTotals::fromSets($snapshot->actualSets);
        $this->sets = new MetricDeviation($this->plannedTotals->sets, $this->actualTotals->sets);
        $this->repetitions = new MetricDeviation($this->plannedTotals->repetitions, $this->actualTotals->repetitions);
        $this->volume = new MetricDeviation($this->plannedTotals->volume, $this->actualTotals->volume);

        $plannedSets = $snapshot->plannedSets->all();
        $actualSets = $snapshot->actualSets->all();
        $comparisons = [];
        $fulfilled = $snapshot->status === ExerciseCompletionStatus::Completed
            && count($actualSets) >= count($plannedSets);

        $positionCount = max(count($plannedSets), count($actualSets));
        $index = 0;

        do {
            $planned = $plannedSets[$index] ?? null;
            $actual = $actualSets[$index] ?? null;
            $comparisons[] = new SetComparison($planned, $actual);

            if ($planned !== null && (
                $actual === null
                || $actual->repetitions->value < $planned->repetitions->value
                || $actual->workingWeight->grams < $planned->workingWeight->grams
            )) {
                $fulfilled = false;
            }

            $index++;
        } while ($index < $positionCount);

        $this->setComparisons = $comparisons;
        $this->planFulfilled = $fulfilled;
    }
}
