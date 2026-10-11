<?php

namespace App\WorkoutExecution\Presentation\Http\Resources;

use App\WorkoutExecution\Application\DTO\EffectiveWorkoutExerciseDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read EffectiveWorkoutExerciseDTO $resource */
final class EffectiveWorkoutExerciseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var EffectiveWorkoutExerciseDTO $exercise */
        $exercise = $this->resource;

        return [
            'exercise_id' => $exercise->exerciseId,
            'name' => $exercise->name,
            'planned_sets' => WorkoutSetResource::collection($exercise->plannedSets),
        ];
    }
}
