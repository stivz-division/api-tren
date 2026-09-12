<?php

namespace App\WorkoutExecution\Presentation\Http\Requests;

use App\WorkoutExecution\Application\UseCases\CompleteExercise\CompleteExerciseInput;

final class CompleteExerciseRequest extends WorkoutExerciseSetsRequest
{
    public function toInput(): CompleteExerciseInput
    {
        return new CompleteExerciseInput(
            $this->authenticatedUserId(),
            $this->workoutSessionId(),
            $this->exerciseId(),
            $this->workoutSetInputs(),
        );
    }

    protected function minimumSets(): int
    {
        return 1;
    }
}
