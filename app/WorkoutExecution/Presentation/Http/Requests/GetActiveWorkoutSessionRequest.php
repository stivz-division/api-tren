<?php

namespace App\WorkoutExecution\Presentation\Http\Requests;

use App\WorkoutExecution\Application\UseCases\GetActiveWorkoutSession\GetActiveWorkoutSessionInput;

final class GetActiveWorkoutSessionRequest extends AuthenticatedWorkoutRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function toInput(): GetActiveWorkoutSessionInput
    {
        return new GetActiveWorkoutSessionInput($this->authenticatedUserId());
    }
}
