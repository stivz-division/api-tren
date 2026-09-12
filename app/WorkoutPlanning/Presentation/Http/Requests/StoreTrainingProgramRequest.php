<?php

namespace App\WorkoutPlanning\Presentation\Http\Requests;

use App\Models\User;
use App\WorkoutPlanning\Application\DTO\PlannedExerciseInput;
use App\WorkoutPlanning\Application\DTO\PlannedSetInput;
use App\WorkoutPlanning\Application\UseCases\CreateTrainingProgram\CreateTrainingProgramInput;
use App\WorkoutPlanning\Presentation\Http\Support\WorkingWeightConverter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

final class StoreTrainingProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'weekday' => ['required', 'integer', 'between:1,7'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'exercises' => ['required', 'array', 'list', 'min:1'],
            'exercises.*' => ['array:exercise_id,sets'],
            'exercises.*.exercise_id' => [
                'required',
                'integer',
                'distinct:strict',
                Rule::exists('exercises', 'id'),
            ],
            'exercises.*.sets' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'exercises.*.sets.*' => ['array:repetitions,working_weight_kg'],
            'exercises.*.sets.*.repetitions' => ['required', 'integer', 'min:1'],
            'exercises.*.sets.*.working_weight_kg' => [
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
            'weekday.required' => 'Укажите день недели.',
            'weekday.integer' => 'День недели должен быть целым числом.',
            'weekday.between' => 'День недели должен быть числом от 1 до 7.',
            'name.string' => 'Название программы должно быть строкой.',
            'name.max' => 'Название программы не должно быть длиннее 255 символов.',
            'exercises.required' => 'Добавьте хотя бы одно упражнение.',
            'exercises.array' => 'Упражнения должны быть переданы списком.',
            'exercises.list' => 'Упражнения должны быть переданы упорядоченным списком.',
            'exercises.min' => 'Добавьте хотя бы одно упражнение.',
            'exercises.*.array' => 'Каждое упражнение должно содержать только идентификатор и подходы.',
            'exercises.*.exercise_id.required' => 'Укажите упражнение.',
            'exercises.*.exercise_id.integer' => 'Идентификатор упражнения должен быть целым числом.',
            'exercises.*.exercise_id.distinct' => 'Одно упражнение нельзя добавить дважды.',
            'exercises.*.exercise_id.exists' => 'Выбранное упражнение не найдено.',
            'exercises.*.sets.required' => 'Добавьте хотя бы один подход.',
            'exercises.*.sets.array' => 'Подходы должны быть переданы списком.',
            'exercises.*.sets.list' => 'Подходы должны быть переданы упорядоченным списком.',
            'exercises.*.sets.min' => 'Добавьте хотя бы один подход.',
            'exercises.*.sets.max' => 'Для одного упражнения можно запланировать не более 100 подходов.',
            'exercises.*.sets.*.array' => 'Каждый подход должен содержать только повторения и рабочий вес.',
            'exercises.*.sets.*.repetitions.required' => 'Укажите количество повторений.',
            'exercises.*.sets.*.repetitions.integer' => 'Количество повторений должно быть целым числом.',
            'exercises.*.sets.*.repetitions.min' => 'Количество повторений должно быть не меньше 1.',
            'exercises.*.sets.*.working_weight_kg.required' => 'Укажите рабочий вес.',
            'exercises.*.sets.*.working_weight_kg.numeric' => 'Рабочий вес должен быть числом.',
            'exercises.*.sets.*.working_weight_kg.min' => 'Рабочий вес не может быть отрицательным.',
            'exercises.*.sets.*.working_weight_kg.max' => 'Рабочий вес превышает допустимое значение.',
            'exercises.*.sets.*.working_weight_kg.decimal' => 'Рабочий вес может содержать не более двух знаков после запятой.',
        ];
    }

    public function toInput(): CreateTrainingProgramInput
    {
        $user = $this->user();

        if (! $user instanceof User) {
            throw new LogicException('Авторизованный пользователь недоступен.');
        }

        /** @var array{
         *     weekday: int,
         *     name?: string|null,
         *     exercises: list<array{
         *         exercise_id: int,
         *         sets: non-empty-list<array{
         *             repetitions: int,
         *             working_weight_kg: int|float|string
         *         }>
         *     }>
         * } $validated
         */
        $validated = $this->validated();

        return new CreateTrainingProgramInput(
            userId: $user->id,
            weekday: $validated['weekday'],
            name: $validated['name'] ?? null,
            exercises: array_map(
                static fn (array $exercise): PlannedExerciseInput => new PlannedExerciseInput(
                    exerciseId: $exercise['exercise_id'],
                    sets: array_map(
                        static fn (array $set): PlannedSetInput => new PlannedSetInput(
                            repetitions: $set['repetitions'],
                            workingWeightInGrams: WorkingWeightConverter::kilogramsToGrams(
                                $set['working_weight_kg'],
                            ),
                        ),
                        $exercise['sets'],
                    ),
                ),
                $validated['exercises'],
            ),
        );
    }
}
