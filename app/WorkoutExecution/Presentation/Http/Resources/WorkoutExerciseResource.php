<?php

namespace App\WorkoutExecution\Presentation\Http\Resources;

use App\WorkoutExecution\Application\DTO\WorkoutExerciseDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read WorkoutExerciseDTO $resource */
final class WorkoutExerciseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkoutExerciseDTO $exercise */
        $exercise = $this->resource;

        return [
            /** Original catalog ID of the session position; use this ID for all exercise commands. */
            'exercise_id' => $exercise->exerciseId,
            'name' => $exercise->name,
            'position' => $exercise->position,
            /** @var 'pending'|'completed'|'skipped' */
            'status' => $exercise->status,
            /** Original planned load captured when the session started. */
            'planned_sets' => WorkoutSetResource::collection($exercise->plannedSets),
            'sets' => WorkoutSetResource::collection($exercise->sets),
            /** Current exercise and load for execution; may differ from the original snapshot after replacement. */
            'effective_exercise' => new EffectiveWorkoutExerciseResource($exercise->effectiveExercise),
            'replacement' => $exercise->replacement === null
                ? null
                : new WorkoutExerciseReplacementResource($exercise->replacement),
            /** False when the latest replacement applies only to this session. */
            'included_in_analysis' => $exercise->includedInAnalysis,
        ];
    }
}
