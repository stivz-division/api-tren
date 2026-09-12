<?php

namespace App\WorkoutPlanning\Presentation\Http\Resources;

use App\WorkoutPlanning\Application\DTO\PlannedExerciseDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PlannedExerciseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var PlannedExerciseDTO $plannedExercise */
        $plannedExercise = $this->resource;

        return [
            'exercise_id' => $plannedExercise->exerciseId,
            'sets' => PlannedSetResource::collection($plannedExercise->sets),
            'position' => $plannedExercise->position,
        ];
    }
}
