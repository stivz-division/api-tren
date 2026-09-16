<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use App\WorkoutAnalysis\Domain\Collections\SetSnapshotCollection;
use OverflowException;

final readonly class LoadTotals
{
    private function __construct(
        public private(set) int $sets,
        public private(set) int $repetitions,
        public private(set) int $volume,
    ) {}

    public static function zero(): self
    {
        return new self(0, 0, 0);
    }

    public static function fromSets(SetSnapshotCollection $sets): self
    {
        $total = self::zero();

        foreach ($sets as $set) {
            $total = $total->plus(new self(1, $set->repetitions->value, $set->volume()));
        }

        return $total;
    }

    public function plus(self $other): self
    {
        return new self(
            self::sum($this->sets, $other->sets),
            self::sum($this->repetitions, $other->repetitions),
            self::sum($this->volume, $other->volume),
        );
    }

    private static function sum(int $left, int $right): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new OverflowException('Суммарная нагрузка превышает диапазон целых чисел.');
        }

        return $left + $right;
    }
}
