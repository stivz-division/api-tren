<?php

namespace App\WorkoutExecution\Presentation\Http\Requests;

use App\WorkoutExecution\Application\UseCases\ReplaceExercise\ReplaceExerciseInput;

final class ReplaceExerciseRequest extends WorkoutExerciseSetsRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'request_id' => ['required', 'uuid'],
            'replacement_exercise_id' => ['required', 'integer', 'min:1'],
            /** @default false */
            'replace_in_plan' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'request_id.required' => 'Укажите идентификатор запроса замены.',
            'request_id.uuid' => 'Идентификатор запроса замены должен быть UUID.',
            'replacement_exercise_id.required' => 'Укажите упражнение для замены.',
            'replacement_exercise_id.integer' => 'Идентификатор упражнения для замены должен быть целым числом.',
            'replacement_exercise_id.min' => 'Идентификатор упражнения для замены должен быть положительным числом.',
            'replace_in_plan.boolean' => 'Укажите, нужно ли сохранить замену в плане: true или false.',
            'sets.required' => 'Для замены упражнения добавьте хотя бы один подход.',
            'sets.min' => 'Для замены упражнения добавьте хотя бы один подход.',
        ];
    }

    public function toInput(): ReplaceExerciseInput
    {
        return new ReplaceExerciseInput(
            userId: $this->authenticatedUserId(),
            workoutSessionId: $this->workoutSessionId(),
            exerciseId: $this->exerciseId(),
            requestId: (string) $this->string('request_id'),
            replacementExerciseId: $this->integer('replacement_exercise_id'),
            sets: $this->workoutSetInputs(),
            replaceInPlan: $this->boolean('replace_in_plan'),
        );
    }

    protected function minimumSets(): int
    {
        return 1;
    }
}
