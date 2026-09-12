<?php

namespace App\WorkoutExecution\Presentation\Http\Requests;

use App\WorkoutExecution\Application\UseCases\CompleteWorkoutSession\CompleteWorkoutSessionInput;

final class CompleteWorkoutSessionRequest extends WorkoutSessionRouteRequest
{
    public function toInput(): CompleteWorkoutSessionInput
    {
        return new CompleteWorkoutSessionInput(
            $this->authenticatedUserId(),
            $this->workoutSessionId(),
        );
    }
}
