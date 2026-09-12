<?php

namespace App\WorkoutExecution\Presentation\Http\Requests;

abstract class WorkoutExerciseRouteRequest extends WorkoutSessionRouteRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'exercise_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'exercise_id.required' => 'Укажите упражнение.',
            'exercise_id.integer' => 'Идентификатор упражнения должен быть целым числом.',
            'exercise_id.min' => 'Идентификатор упражнения должен быть положительным числом.',
        ];
    }

    protected function exerciseId(): int
    {
        return $this->integer('exercise_id');
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge([
            'exercise_id' => $this->route('exerciseId'),
        ]);
    }
}
