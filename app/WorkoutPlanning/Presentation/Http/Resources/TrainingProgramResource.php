<?php

namespace App\WorkoutPlanning\Presentation\Http\Resources;

use App\WorkoutPlanning\Application\DTO\TrainingProgramDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TrainingProgramResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TrainingProgramDTO $trainingProgram */
        $trainingProgram = $this->resource;

        return [
            'id' => $trainingProgram->id,
            'weekday' => $trainingProgram->weekday,
            'name' => $trainingProgram->name,
            'exercises' => PlannedExerciseResource::collection(
                $trainingProgram->exercises,
            ),
        ];
    }
}
