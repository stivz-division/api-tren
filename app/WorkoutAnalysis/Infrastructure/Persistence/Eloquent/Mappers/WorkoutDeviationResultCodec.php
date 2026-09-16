<?php

namespace App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers;

use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseDeviation;
use App\WorkoutAnalysis\Domain\ValueObjects\LoadTotals;
use App\WorkoutAnalysis\Domain\ValueObjects\MetricDeviation;
use App\WorkoutAnalysis\Domain\ValueObjects\SetComparison;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutDeviationResult;
use UnexpectedValueException;

final readonly class WorkoutDeviationResultCodec
{
    public const int VERSION = 1;

    public function __construct(private CompletedWorkoutSnapshotCodec $snapshotCodec) {}

    /** @return array<string, mixed> */
    public function encode(WorkoutDeviationResult $result): array
    {
        return [
            'completed_exercises' => $result->completedExercises,
            'skipped_exercises' => $result->skippedExercises,
            'sets' => $this->metric($result->sets),
            'repetitions' => $this->metric($result->repetitions),
            'volume' => $this->metric($result->volume),
            'exercises' => array_map(fn (ExerciseDeviation $exercise): array => [
                'exercise_id' => $exercise->snapshot->exerciseId->value,
                'planned_totals' => $this->totals($exercise->plannedTotals),
                'actual_totals' => $this->totals($exercise->actualTotals),
                'sets' => $this->metric($exercise->sets),
                'repetitions' => $this->metric($exercise->repetitions),
                'volume' => $this->metric($exercise->volume),
                'plan_fulfilled' => $exercise->planFulfilled,
                'set_comparisons' => array_map(fn (SetComparison $comparison): array => [
                    'position' => $comparison->position->value,
                    'planned' => $comparison->planned === null ? null : $this->snapshotCodec->encodeSet($comparison->planned),
                    'actual' => $comparison->actual === null ? null : $this->snapshotCodec->encodeSet($comparison->actual),
                    'repetitions' => $this->metric($comparison->repetitions),
                    'working_weight' => $this->metric($comparison->workingWeight),
                    'volume' => $this->metric($comparison->volume),
                ], $exercise->setComparisons),
            ], $result->exercises),
        ];
    }

    /** @param array<string, mixed> $payload */
    public function decode(array $payload, int $version, CompletedWorkoutSnapshot $snapshot): WorkoutDeviationResult
    {
        if ($version !== self::VERSION) {
            throw new UnexpectedValueException('Неподдерживаемая версия формата результата сравнения тренировки.');
        }

        $snapshots = $snapshot->exercises->all();
        $exercises = [];
        foreach (AnalysisPayload::list($payload['exercises'] ?? null) as $index => $item) {
            $exercise = AnalysisPayload::object($item);
            $exerciseSnapshot = $snapshots[$index] ?? null;
            if ($exerciseSnapshot === null || $exerciseSnapshot->exerciseId->value !== AnalysisPayload::integer($exercise['exercise_id'] ?? null)) {
                throw new UnexpectedValueException('Упражнения результата не соответствуют снимку тренировки.');
            }

            $exercises[] = new ExerciseDeviation($exerciseSnapshot);
        }

        $result = new WorkoutDeviationResult($snapshot, ...$exercises);
        if (! AnalysisPayload::equals($this->encode($result), $payload)) {
            throw new UnexpectedValueException('Некорректный сохранённый результат сравнения тренировки.');
        }

        return $result;
    }

    /** @return array{planned: int, actual: int, difference: int, percentage: float|null}|null */
    private function metric(?MetricDeviation $metric): ?array
    {
        return $metric === null ? null : [
            'planned' => $metric->planned,
            'actual' => $metric->actual,
            'difference' => $metric->difference,
            'percentage' => $metric->percentage,
        ];
    }

    /** @return array{sets: int, repetitions: int, volume: int} */
    private function totals(LoadTotals $totals): array
    {
        return ['sets' => $totals->sets, 'repetitions' => $totals->repetitions, 'volume' => $totals->volume];
    }
}
