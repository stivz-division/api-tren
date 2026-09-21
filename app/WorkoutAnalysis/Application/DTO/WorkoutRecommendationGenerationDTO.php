<?php

namespace App\WorkoutAnalysis\Application\DTO;

use App\WorkoutAnalysis\Domain\Entities\WorkoutRecommendationGeneration;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendation;

final readonly class WorkoutRecommendationGenerationDTO
{
    /**
     * @param  list<string>  $rejectedReasons
     * @param  list<WorkoutRecommendationDTO>|null  $items
     */
    public function __construct(
        public string $status,
        public ?string $failureCode,
        public ?string $noChangeReason,
        public array $rejectedReasons,
        public ?array $items,
    ) {}

    /** @param list<HistoricalRecommendation> $items */
    public static function fromDomain(WorkoutRecommendationGeneration $stage, array $items = []): self
    {
        return new self(
            $stage->status()->value,
            $stage->status() === AnalysisStatus::Failed ? $stage->currentAttempt()->failureCode?->value : null,
            $stage->result?->noChangeReason,
            $stage->rejectedReasons,
            $stage->status() === AnalysisStatus::Completed ? array_map(WorkoutRecommendationDTO::fromDomain(...), $items) : null,
        );
    }
}
