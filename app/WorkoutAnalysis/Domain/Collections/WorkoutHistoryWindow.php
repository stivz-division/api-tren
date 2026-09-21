<?php

namespace App\WorkoutAnalysis\Domain\Collections;

use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutHistoryEntry;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, WorkoutHistoryEntry> */
final readonly class WorkoutHistoryWindow implements Countable, IteratorAggregate
{
    /** @var list<WorkoutHistoryEntry> */
    private array $entries;

    public function __construct(
        public private(set) int $limit = 20,
        WorkoutHistoryEntry ...$entries,
    ) {
        if ($limit < 1 || count($entries) > $limit) {
            throw new InvalidAnalysisContext('Лимит истории должен быть положительным и вмещать все выбранные записи.');
        }

        $ids = [];
        foreach ($entries as $entry) {
            $id = $entry->deviations->snapshot->workoutSessionId->value;
            if (isset($ids[$id])) {
                throw new InvalidAnalysisContext('Сессия не может повторяться в окне истории.');
            }
            $ids[$id] = true;
        }

        $entries = array_values($entries);
        usort($entries, static function (WorkoutHistoryEntry $left, WorkoutHistoryEntry $right): int {
            $first = $left->deviations->snapshot;
            $second = $right->deviations->snapshot;

            return ($first->completedAt <=> $second->completedAt)
                ?: ($first->workoutSessionId->value <=> $second->workoutSessionId->value);
        });

        $this->entries = $entries;
    }

    /** @return list<WorkoutHistoryEntry> */
    public function all(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return Traversable<int, WorkoutHistoryEntry> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->entries);
    }
}
