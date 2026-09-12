<?php

namespace App\WorkoutExecution\Presentation\Http\Requests;

abstract class WorkoutSessionRouteRequest extends AuthenticatedWorkoutRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'workout_session_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'workout_session_id.required' => 'Укажите тренировочную сессию.',
            'workout_session_id.integer' => 'Идентификатор тренировочной сессии должен быть целым числом.',
            'workout_session_id.min' => 'Идентификатор тренировочной сессии должен быть положительным числом.',
        ];
    }

    protected function workoutSessionId(): int
    {
        return $this->integer('workout_session_id');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'workout_session_id' => $this->route('workoutSessionId'),
        ]);
    }
}
