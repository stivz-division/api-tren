<?php

namespace App\WorkoutAnalysis\Presentation\Http\Resources;

use App\WorkoutAnalysis\Application\DTO\WorkoutAIAnalysisDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read WorkoutAIAnalysisDTO $resource */
final class WorkoutAIAnalysisResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkoutAIAnalysisDTO $value */
        $value = $this->resource;

        return [
            /** @var 'pending'|'processing'|'completed'|'failed' */
            'status' => $value->status,
            /** @var 'provider_unavailable'|'provider_rejected'|'ai_refused'|'invalid_ai_response'|'incomplete_ai_response'|'context_preparation_failed'|'worker_failed'|'attempt_timed_out'|null */
            'failure_code' => $value->failureCode,
            'result' => $value->result,
        ];
    }
}
