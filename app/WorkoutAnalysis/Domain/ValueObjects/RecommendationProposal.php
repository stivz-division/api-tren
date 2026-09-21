<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use App\WorkoutAnalysis\Domain\Collections\SetSnapshotCollection;
use InvalidArgumentException;

final readonly class RecommendationProposal
{
    /** @var list<AnalysisEvidenceReference> */
    public array $evidence;

    public function __construct(
        public int $exerciseId,
        public string $changeType,
        public ?int $replacementExerciseId,
        public SetSnapshotCollection $proposedSets,
        public string $rationale,
        AnalysisEvidenceReference ...$evidence,
    ) {
        if ($exerciseId < 1 || ! in_array($changeType, ['progression', 'adjustment', 'replacement'], true)
            || ($replacementExerciseId !== null && $replacementExerciseId < 1)
            || ($changeType === 'replacement') !== ($replacementExerciseId !== null)
            || $proposedSets->count() === 0 || trim($rationale) === '' || $evidence === []) {
            throw new InvalidArgumentException('Предложение должно содержать упражнение, целостный план, обоснование и основания.');
        }
        $this->evidence = array_values($evidence);
    }
}
