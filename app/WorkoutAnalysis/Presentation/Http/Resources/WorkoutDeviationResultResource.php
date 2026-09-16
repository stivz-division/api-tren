<?php

namespace App\WorkoutAnalysis\Presentation\Http\Resources;

use App\WorkoutAnalysis\Application\DTO\WorkoutDeviationResultDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property-read WorkoutDeviationResultDTO $resource */
final class WorkoutDeviationResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkoutDeviationResultDTO $value */
        $value = $this->resource;

        return [
            'training_program_id' => $value->trainingProgramId,
            'program_name' => $value->programName,
            /** @format date-time */
            'workout_completed_at' => $value->workoutCompletedAt->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM),
            'completed_exercises' => $value->completedExercises,
            'skipped_exercises' => $value->skippedExercises,
            'sets' => new CountDeviationResource($value->sets),
            'repetitions' => new CountDeviationResource($value->repetitions),
            /** Объём: сумма веса в килограммах × повторения. */
            'volume_kg' => new KilogramDeviationResource($value->volume),
            'exercises' => ExerciseDeviationResource::collection($value->exercises),
        ];
    }
}
