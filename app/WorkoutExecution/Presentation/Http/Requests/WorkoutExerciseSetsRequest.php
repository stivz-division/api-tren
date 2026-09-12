<?php

namespace App\WorkoutExecution\Presentation\Http\Requests;

use App\WorkoutExecution\Application\DTO\WorkoutSetInput;
use App\WorkoutExecution\Presentation\Http\Support\WorkingWeightConverter;

abstract class WorkoutExerciseSetsRequest extends WorkoutExerciseRouteRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $presenceRule = $this->minimumSets() === 0 ? 'present' : 'required';

        return [
            ...parent::rules(),
            'sets' => [$presenceRule, 'array', 'list', 'min:'.$this->minimumSets(), 'max:100'],
            'sets.*' => ['array:repetitions,working_weight_kg'],
            'sets.*.repetitions' => ['required', 'integer', 'min:1'],
            'sets.*.working_weight_kg' => [
                'required',
                'numeric',
                'min:0',
                'max:'.WorkingWeightConverter::MAX_KILOGRAMS,
                'decimal:0,2',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'sets.required' => 'Передайте список подходов.',
            'sets.present' => 'Передайте список подходов.',
            'sets.array' => 'Подходы должны быть переданы списком.',
            'sets.list' => 'Подходы должны быть переданы упорядоченным списком.',
            'sets.min' => 'Для завершения упражнения добавьте хотя бы один подход.',
            'sets.max' => 'За один раз можно сохранить не более 100 подходов.',
            'sets.*.array' => 'Каждый подход должен содержать только повторения и рабочий вес.',
            'sets.*.repetitions.required' => 'Укажите количество повторений.',
            'sets.*.repetitions.integer' => 'Количество повторений должно быть целым числом.',
            'sets.*.repetitions.min' => 'Количество повторений должно быть не меньше 1.',
            'sets.*.working_weight_kg.required' => 'Укажите рабочий вес.',
            'sets.*.working_weight_kg.numeric' => 'Рабочий вес должен быть числом.',
            'sets.*.working_weight_kg.min' => 'Рабочий вес не может быть отрицательным.',
            'sets.*.working_weight_kg.max' => 'Рабочий вес превышает допустимое значение.',
            'sets.*.working_weight_kg.decimal' => 'Рабочий вес может содержать не более двух знаков после запятой.',
        ];
    }

    /** @return list<WorkoutSetInput> */
    protected function workoutSetInputs(): array
    {
        /** @var array{sets: list<array{repetitions: int, working_weight_kg: int|float|string}>} $validated */
        $validated = $this->validated();

        return array_map(
            static fn (array $set): WorkoutSetInput => new WorkoutSetInput(
                repetitions: $set['repetitions'],
                workingWeightInGrams: WorkingWeightConverter::kilogramsToGrams(
                    $set['working_weight_kg'],
                ),
            ),
            $validated['sets'],
        );
    }

    abstract protected function minimumSets(): int;
}
