<?php

namespace App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers;

use App\WorkoutAnalysis\Domain\Collections\SetSnapshotCollection;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisEvidenceReference;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendation;
use App\WorkoutAnalysis\Domain\ValueObjects\Repetitions;
use App\WorkoutAnalysis\Domain\ValueObjects\SetPosition;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkingWeight;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSetSnapshot;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutRecommendationModel;

final class WorkoutRecommendationMapper
{
    public function toDomain(WorkoutRecommendationModel $model): HistoricalRecommendation
    {
        $dates = ['applied' => $model->applied_at, 'rejected' => $model->rejected_at, 'expired' => $model->expired_at];
        if (! in_array($model->status, ['proposed', 'applied', 'rejected', 'expired'], true)
            || ! in_array($model->change_type, ['progression', 'adjustment', 'replacement'], true)
            || (($model->change_type === 'replacement') !== ($model->replacement_exercise_id !== null))) {
            throw new \UnexpectedValueException('Некорректное состояние рекомендации.');
        }
        foreach ($dates as $status => $date) {
            if (($model->status === $status) !== ($date !== null) || ($date !== null && $date < $model->source_completed_at)) {
                throw new \UnexpectedValueException('Время решения не соответствует состоянию рекомендации.');
            }
        }

        return new HistoricalRecommendation(
            $model->id, new ExerciseId($model->exercise_id), $this->sets($model->original_sets),
            $model->change_type, $model->replacement_exercise_id === null ? null : new ExerciseId($model->replacement_exercise_id),
            $this->sets($model->proposed_sets), $model->rationale, $model->status,
            $model->applied_at?->toDateTimeImmutable(), $model->rejected_at?->toDateTimeImmutable(), $model->expired_at?->toDateTimeImmutable(),
            ...array_map(static fn (array $item): AnalysisEvidenceReference => new AnalysisEvidenceReference(
                new WorkoutAnalysisId($item['analysis_id']), new WorkoutSessionId($item['workout_session_id']),
                $item['exercise_id'] === null ? null : new ExerciseId($item['exercise_id']),
            ), $model->evidence),
        );
    }

    /** @param list<array{position:int,repetitions:int,working_weight_grams:int}> $sets */
    public function sets(array $sets): SetSnapshotCollection
    {
        return new SetSnapshotCollection(...array_map(static fn (array $set): WorkoutSetSnapshot => new WorkoutSetSnapshot(
            new SetPosition($set['position']), new Repetitions($set['repetitions']), new WorkingWeight($set['working_weight_grams']),
        ), $sets));
    }

    /** @return list<array{position:int,repetitions:int,working_weight_grams:int}> */
    public function encodeSets(SetSnapshotCollection $sets): array
    {
        return array_map(static fn (WorkoutSetSnapshot $set): array => [
            'position' => $set->position->value, 'repetitions' => $set->repetitions->value, 'working_weight_grams' => $set->workingWeight->grams,
        ], $sets->all());
    }
}
