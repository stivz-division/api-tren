<?php

namespace App\WorkoutExecution\Presentation\Http\Resources;

use App\WorkoutExecution\Application\DTO\WorkoutSessionDTO;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkoutSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkoutSessionDTO $session */
        $session = $this->resource;

        return [
            'id' => $session->id,
            'training_program_id' => $session->trainingProgramId,
            'program_name' => $session->programName,
            'scheduled_weekday' => $session->scheduledWeekday,
            'status' => $session->status,
            'started_at' => $session->startedAt->format(DATE_ATOM),
            'completed_at' => $session->completedAt?->format(DATE_ATOM),
            'cancelled_at' => $session->cancelledAt?->format(DATE_ATOM),
            'exercises' => WorkoutExerciseResource::collection($session->exercises),
        ];
    }
}
