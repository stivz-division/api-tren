<?php

namespace App\WorkoutExecution\Presentation\Http\Resources;

use App\WorkoutExecution\Application\DTO\WorkoutSetDTO;
use App\WorkoutExecution\Presentation\Http\Support\WorkingWeightConverter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkoutSetResource extends JsonResource
{
    /** @return array{position: int, repetitions: int, working_weight_kg: int|float} */
    public function toArray(Request $request): array
    {
        /** @var WorkoutSetDTO $set */
        $set = $this->resource;

        return [
            'position' => $set->position,
            'repetitions' => $set->repetitions,
            'working_weight_kg' => WorkingWeightConverter::gramsToKilograms(
                $set->workingWeightInGrams,
            ),
        ];
    }
}
