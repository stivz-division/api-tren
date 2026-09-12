<?php

namespace App\WorkoutExecution\Presentation\Http\Requests;

use App\WorkoutExecution\Application\UseCases\StartWorkoutSession\StartWorkoutSessionInput;

final class StartWorkoutSessionRequest extends AuthenticatedWorkoutRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'training_program_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'training_program_id.required' => 'Укажите программу тренировки.',
            'training_program_id.integer' => 'Идентификатор программы тренировки должен быть целым числом.',
            'training_program_id.min' => 'Идентификатор программы тренировки должен быть положительным числом.',
        ];
    }

    public function toInput(): StartWorkoutSessionInput
    {
        return new StartWorkoutSessionInput(
            $this->authenticatedUserId(),
            $this->integer('training_program_id'),
        );
    }
}
