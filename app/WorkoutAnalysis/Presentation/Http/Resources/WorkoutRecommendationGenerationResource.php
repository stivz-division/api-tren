<?php

namespace App\WorkoutAnalysis\Presentation\Http\Resources;

use App\WorkoutAnalysis\Application\DTO\WorkoutRecommendationGenerationDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read WorkoutRecommendationGenerationDTO $resource */
final class WorkoutRecommendationGenerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkoutRecommendationGenerationDTO $value */
        $value = $this->resource;

        return [
            /** @var 'pending'|'processing'|'completed'|'failed' */
            'status' => $value->status,
            /** @var 'provider_unavailable'|'provider_rejected'|'ai_refused'|'invalid_ai_response'|'incomplete_ai_response'|'context_preparation_failed'|'recommendations_rejected'|'worker_failed'|'attempt_timed_out'|null */
            'failure_code' => $value->failureCode,
            'no_change_reason' => $value->noChangeReason,
            'rejected_reasons' => $value->rejectedReasons,
            'items' => $value->items === null ? null : WorkoutRecommendationResource::collection($value->items),
        ];
    }
}
