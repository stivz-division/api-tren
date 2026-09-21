<?php

namespace App\WorkoutAnalysis\Application\DTO;

use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use LogicException;

final readonly class WorkoutDeviationAnalysisDTO
{
    /** @param non-empty-list<AnalysisAttemptDTO> $attempts */
    public function __construct(
        public private(set) int $id,
        public private(set) int $userId,
        public private(set) int $workoutSessionId,
        public private(set) string $status,
        public private(set) array $attempts,
        public private(set) ?WorkoutDeviationResultDTO $result,
        public private(set) ?WorkoutAIAnalysisDTO $ai = null,
    ) {}

    public static function fromDomain(WorkoutAnalysis $analysis): self
    {
        $id = $analysis->id ?? throw new LogicException('Нельзя вернуть несохранённый анализ.');
        $stage = $analysis->deviations();

        return new self(
            $id->value,
            $stage->snapshot->userId->value,
            $stage->snapshot->workoutSessionId->value,
            $stage->status()->value,
            array_map(AnalysisAttemptDTO::fromDomain(...), $stage->attempts()),
            $stage->result === null ? null : WorkoutDeviationResultDTO::fromDomain($stage->result),
            $analysis->ai() === null ? null : WorkoutAIAnalysisDTO::fromDomain($analysis->ai()),
        );
    }
}
