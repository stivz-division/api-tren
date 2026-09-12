<?php

namespace App\WorkoutExecution\Presentation\Http\Requests;

use App\WorkoutExecution\Application\UseCases\CancelWorkoutSession\CancelWorkoutSessionInput;

final class CancelWorkoutSessionRequest extends WorkoutSessionRouteRequest
{
    public function toInput(): CancelWorkoutSessionInput
    {
        return new CancelWorkoutSessionInput(
            $this->authenticatedUserId(),
            $this->workoutSessionId(),
        );
    }
}
