<?php

namespace App\WorkoutAnalysis\Presentation\Http\Resources;

use App\WorkoutAnalysis\Application\DTO\WorkoutDeviationAnalysisDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read WorkoutDeviationAnalysisDTO $resource */
final class WorkoutAnalysisResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkoutDeviationAnalysisDTO $value */
        $value = $this->resource;

        $attempt = $value->attempts[array_key_last($value->attempts)];

        return [
            'id' => $value->id,
            'workout_session_id' => $value->workoutSessionId,
            /** @var 'pending'|'processing'|'completed'|'failed' */
            'status' => $value->status,
            /** @var 'calculation_failed'|'arithmetic_overflow'|'worker_failed'|'attempt_timed_out'|null */
            'failure_code' => $value->status === 'failed' ? $attempt->failureCode : null,
            'result' => $value->result === null ? null : new WorkoutDeviationResultResource($value->result),
            'ai_analysis' => $value->ai === null ? null : new WorkoutAIAnalysisResource($value->ai),
        ];
    }
}
