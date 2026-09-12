<?php

namespace App\WorkoutExecution\Presentation\Http\Requests;

use App\WorkoutExecution\Application\UseCases\SkipExercise\SkipExerciseInput;

final class SkipExerciseRequest extends WorkoutExerciseRouteRequest
{
    public function toInput(): SkipExerciseInput
    {
        return new SkipExerciseInput(
            $this->authenticatedUserId(),
            $this->workoutSessionId(),
            $this->exerciseId(),
        );
    }
}
