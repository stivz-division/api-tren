<?php

namespace App\WorkoutExecution\Presentation\Http\Requests;

use App\WorkoutExecution\Application\UseCases\SaveExerciseProgress\SaveExerciseProgressInput;

final class SaveExerciseProgressRequest extends WorkoutExerciseSetsRequest
{
    public function toInput(): SaveExerciseProgressInput
    {
        return new SaveExerciseProgressInput(
            $this->authenticatedUserId(),
            $this->workoutSessionId(),
            $this->exerciseId(),
            $this->workoutSetInputs(),
        );
    }

    protected function minimumSets(): int
    {
        return 0;
    }
}
