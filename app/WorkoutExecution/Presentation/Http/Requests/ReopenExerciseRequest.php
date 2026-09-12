<?php

namespace App\WorkoutExecution\Presentation\Http\Requests;

use App\WorkoutExecution\Application\UseCases\ReopenExercise\ReopenExerciseInput;

final class ReopenExerciseRequest extends WorkoutExerciseRouteRequest
{
    public function toInput(): ReopenExerciseInput
    {
        return new ReopenExerciseInput(
            $this->authenticatedUserId(),
            $this->workoutSessionId(),
            $this->exerciseId(),
        );
    }
}
