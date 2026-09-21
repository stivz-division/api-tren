<?php

namespace App\WorkoutAnalysis\Presentation\Http\Resources;

use App\WorkoutAnalysis\Application\DTO\WorkoutRecommendationDTO;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read WorkoutRecommendationDTO $resource */
final class WorkoutRecommendationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkoutRecommendationDTO $value */
        $value = $this->resource;

        return [
            'id' => $value->id,
            'exercise_id' => $value->exerciseId,
            /** @var 'progression'|'adjustment'|'replacement' */
            'change_type' => $value->changeType,
            'replacement_exercise_id' => $value->replacementExerciseId,
            'original_sets' => AnalysisSetResource::collection($value->originalSets),
            'proposed_sets' => AnalysisSetResource::collection($value->proposedSets),
            'rationale' => $value->rationale,
            /** @var 'proposed'|'applied'|'rejected'|'expired' */
            'status' => $value->status,
            'applied_at' => $value->appliedAt?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'rejected_at' => $value->rejectedAt?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'expired_at' => $value->expiredAt?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'evidence' => $value->evidence,
        ];
    }
}
