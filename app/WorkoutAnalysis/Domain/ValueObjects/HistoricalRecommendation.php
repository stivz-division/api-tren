<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use App\WorkoutAnalysis\Domain\Collections\SetSnapshotCollection;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;
use DateTimeImmutable;

final readonly class HistoricalRecommendation
{
    /** @var list<AnalysisEvidenceReference> */
    public private(set) array $evidence;

    /** Тип и статус копируются из сохранённой рекомендации; её жизненный цикл здесь не моделируется. */
    public function __construct(
        public private(set) int $id,
        public private(set) ExerciseId $exerciseId,
        public private(set) SetSnapshotCollection $originalSets,
        public private(set) string $changeType,
        public private(set) ?ExerciseId $replacementExerciseId,
        public private(set) SetSnapshotCollection $proposedSets,
        public private(set) string $rationale,
        public private(set) string $status,
        public private(set) ?DateTimeImmutable $appliedAt = null,
        public private(set) ?DateTimeImmutable $rejectedAt = null,
        public private(set) ?DateTimeImmutable $expiredAt = null,
        AnalysisEvidenceReference ...$evidence,
    ) {
        if ($id < 1 || trim($changeType) === '' || trim($rationale) === '' || trim($status) === '') {
            throw new InvalidAnalysisContext('Снимок рекомендации должен содержать ID, тип, обоснование и состояние.');
        }

        if ($originalSets->count() === 0 || $proposedSets->count() === 0) {
            throw new InvalidAnalysisContext('Исходный и предлагаемый планы рекомендации должны содержать подходы.');
        }

        if ($replacementExerciseId == $exerciseId) {
            throw new InvalidAnalysisContext('Нельзя заменить упражнение самим собой.');
        }

        $this->evidence = array_values($evidence);
    }
}
