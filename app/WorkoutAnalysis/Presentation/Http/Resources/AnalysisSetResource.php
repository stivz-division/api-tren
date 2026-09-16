<?php

namespace App\WorkoutAnalysis\Presentation\Http\Resources;

use App\WorkoutAnalysis\Application\DTO\SetSnapshotData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read SetSnapshotData $resource */
final class AnalysisSetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SetSnapshotData $value */
        $value = $this->resource;

        return [
            'position' => $value->position,
            'repetitions' => $value->repetitions,
            'working_weight_kg' => $value->workingWeightInGrams / 1000,
        ];
    }
}
