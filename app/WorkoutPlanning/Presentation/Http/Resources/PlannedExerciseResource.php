<?php

namespace App\WorkoutPlanning\Presentation\Http\Resources;

use App\WorkoutPlanning\Application\DTO\PlannedExerciseDTO;
use App\WorkoutPlanning\Presentation\Http\Support\WorkingWeightConverter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PlannedExerciseResource extends JsonResource
{
    /**
     * @return array{
     *     exercise_id: int,
     *     sets: int,
     *     repetitions_per_set: int,
     *     working_weight_kg: int|float,
     *     position: int
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var PlannedExerciseDTO $plannedExercise */
        $plannedExercise = $this->resource;

        return [
            'exercise_id' => $plannedExercise->exerciseId,
            'sets' => $plannedExercise->sets,
            'repetitions_per_set' => $plannedExercise->repetitionsPerSet,
            'working_weight_kg' => WorkingWeightConverter::gramsToKilograms(
                $plannedExercise->workingWeightInGrams,
            ),
            'position' => $plannedExercise->position,
        ];
    }
}
