<?php

namespace App\WorkoutExecution\Presentation\Http\Resources;

use App\WorkoutExecution\Application\DTO\WorkoutExerciseReplacementDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read WorkoutExerciseReplacementDTO $resource */
final class WorkoutExerciseReplacementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkoutExerciseReplacementDTO $exercise */
        $exercise = $this->resource;

        return [
            /**
             * UUID identifying the latest replacement operation, normalized to lowercase.
             *
             * @format uuid
             */
            'request_id' => $exercise->requestId,
            'replace_in_plan' => $exercise->replaceInPlan,
        ];
    }
}
