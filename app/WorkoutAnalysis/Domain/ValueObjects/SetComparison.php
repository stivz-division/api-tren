<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use InvalidArgumentException;

/** Порядковое сравнение не устанавливает связь фактического подхода с намерением пользователя. */
final readonly class SetComparison
{
    public private(set) SetPosition $position;

    public private(set) ?MetricDeviation $repetitions;

    public private(set) ?MetricDeviation $workingWeight;

    public private(set) ?MetricDeviation $volume;

    public function __construct(
        public private(set) ?WorkoutSetSnapshot $planned,
        public private(set) ?WorkoutSetSnapshot $actual,
    ) {
        $set = $planned ?? $actual;

        if ($set === null) {
            throw new InvalidArgumentException('Для сравнения необходим хотя бы один подход.');
        }

        if ($planned !== null && $actual !== null && $planned->position->value !== $actual->position->value) {
            throw new InvalidArgumentException('Сравнивать можно только подходы на одинаковой порядковой позиции.');
        }

        $this->position = $set->position;

        if ($planned === null || $actual === null) {
            $this->repetitions = null;
            $this->workingWeight = null;
            $this->volume = null;

            return;
        }

        $this->repetitions = new MetricDeviation($planned->repetitions->value, $actual->repetitions->value);
        $this->workingWeight = new MetricDeviation($planned->workingWeight->grams, $actual->workingWeight->grams);
        $this->volume = new MetricDeviation($planned->volume(), $actual->volume());
    }
}
