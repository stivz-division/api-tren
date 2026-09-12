<?php

namespace App\WorkoutExecution\Presentation\Http\Resources;

use App\WorkoutExecution\Application\DTO\WorkoutExerciseDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkoutExerciseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkoutExerciseDTO $exercise */
        $exercise = $this->resource;

        return [
            'exercise_id' => $exercise->exerciseId,
            'name' => $exercise->name,
            'position' => $exercise->position,
            'status' => $exercise->status,
            'planned_sets' => WorkoutSetResource::collection($exercise->plannedSets),
            'sets' => WorkoutSetResource::collection($exercise->sets),
        ];
    }
}
