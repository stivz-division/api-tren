<?php

namespace App\WorkoutPlanning\Presentation\Http\Resources;

use App\WorkoutPlanning\Application\DTO\PlannedSetDTO;
use App\WorkoutPlanning\Presentation\Http\Support\WorkingWeightConverter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PlannedSetResource extends JsonResource
{
    /** @return array{position: int, repetitions: int, working_weight_kg: int|float} */
    public function toArray(Request $request): array
    {
        /** @var PlannedSetDTO $set */
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
