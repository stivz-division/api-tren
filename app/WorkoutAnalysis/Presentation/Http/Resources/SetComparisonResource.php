<?php

namespace App\WorkoutAnalysis\Presentation\Http\Resources;

use App\WorkoutAnalysis\Application\DTO\SetComparisonDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read SetComparisonDTO $resource */
final class SetComparisonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SetComparisonDTO $value */
        $value = $this->resource;

        return [
            'position' => $value->position,
            'planned' => $value->planned === null ? null : new AnalysisSetResource($value->planned),
            'actual' => $value->actual === null ? null : new AnalysisSetResource($value->actual),
            'repetitions' => $value->repetitions === null ? null : new CountDeviationResource($value->repetitions),
            'working_weight_kg' => $value->workingWeight === null ? null : new KilogramDeviationResource($value->workingWeight),
            'volume_kg' => $value->volume === null ? null : new KilogramDeviationResource($value->volume),
        ];
    }
}
