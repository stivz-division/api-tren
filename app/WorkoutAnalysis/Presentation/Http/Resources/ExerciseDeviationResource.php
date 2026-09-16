<?php

namespace App\WorkoutAnalysis\Presentation\Http\Resources;

use App\WorkoutAnalysis\Application\DTO\ExerciseDeviationDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read ExerciseDeviationDTO $resource */
final class ExerciseDeviationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ExerciseDeviationDTO $value */
        $value = $this->resource;

        return [
            'exercise_id' => $value->snapshot->exerciseId,
            'name' => $value->snapshot->name,
            'position' => $value->snapshot->position,
            /** @var 'completed'|'skipped' */
            'status' => $value->snapshot->status,
            'planned_sets' => AnalysisSetResource::collection($value->snapshot->plannedSets),
            'actual_sets' => AnalysisSetResource::collection($value->snapshot->actualSets),
            'set_comparisons' => SetComparisonResource::collection($value->setComparisons),
            'sets' => new CountDeviationResource($value->sets),
            'repetitions' => new CountDeviationResource($value->repetitions),
            'volume_kg' => new KilogramDeviationResource($value->volume),
            'plan_fulfilled' => $value->planFulfilled,
        ];
    }
}
