<?php

namespace App\WorkoutPlanning\Presentation\Http\Requests;

use App\Models\User;
use App\WorkoutPlanning\Application\DTO\PlannedExerciseInput;
use App\WorkoutPlanning\Application\UseCases\UpdateTrainingProgram\UpdateTrainingProgramInput;
use App\WorkoutPlanning\Presentation\Http\Support\WorkingWeightConverter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

final class UpdateTrainingProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'weekday' => ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'exercises' => ['required', 'array', 'min:1'],
            'exercises.*.exercise_id' => [
                'required',
                'integer',
                'distinct:strict',
                Rule::exists('exercises', 'id'),
            ],
            'exercises.*.sets' => ['required', 'integer', 'min:1'],
            'exercises.*.repetitions_per_set' => ['required', 'integer', 'min:1'],
            'exercises.*.working_weight_kg' => [
                'required',
                'numeric',
                'min:0',
                'decimal:0,2',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'weekday.prohibited' => 'Программу нельзя перенести на другой день.',
            'name.required' => 'Укажите название программы.',
            'name.string' => 'Название программы должно быть строкой.',
            'name.max' => 'Название программы не должно быть длиннее 255 символов.',
            'exercises.required' => 'Добавьте хотя бы одно упражнение.',
            'exercises.array' => 'Упражнения должны быть переданы списком.',
            'exercises.min' => 'Добавьте хотя бы одно упражнение.',
            'exercises.*.exercise_id.required' => 'Укажите упражнение.',
            'exercises.*.exercise_id.integer' => 'Идентификатор упражнения должен быть целым числом.',
            'exercises.*.exercise_id.distinct' => 'Одно упражнение нельзя добавить дважды.',
            'exercises.*.exercise_id.exists' => 'Выбранное упражнение не найдено.',
            'exercises.*.sets.required' => 'Укажите количество подходов.',
            'exercises.*.sets.integer' => 'Количество подходов должно быть целым числом.',
            'exercises.*.sets.min' => 'Количество подходов должно быть не меньше 1.',
            'exercises.*.repetitions_per_set.required' => 'Укажите количество повторений.',
            'exercises.*.repetitions_per_set.integer' => 'Количество повторений должно быть целым числом.',
            'exercises.*.repetitions_per_set.min' => 'Количество повторений должно быть не меньше 1.',
            'exercises.*.working_weight_kg.required' => 'Укажите рабочий вес.',
            'exercises.*.working_weight_kg.numeric' => 'Рабочий вес должен быть числом.',
            'exercises.*.working_weight_kg.min' => 'Рабочий вес не может быть отрицательным.',
            'exercises.*.working_weight_kg.decimal' => 'Рабочий вес может содержать не более двух знаков после запятой.',
        ];
    }

    public function toInput(): UpdateTrainingProgramInput
    {
        $user = $this->user();

        if (! $user instanceof User) {
            throw new LogicException('Авторизованный пользователь недоступен.');
        }

        /** @var array{
         *     name: string,
         *     exercises: list<array{
         *         exercise_id: int,
         *         sets: int,
         *         repetitions_per_set: int,
         *         working_weight_kg: int|float|string
         *     }>
         * } $validated
         */
        $validated = $this->validated();

        return new UpdateTrainingProgramInput(
            userId: $user->id,
            trainingProgramId: (int) $this->route('trainingProgramId'),
            name: $validated['name'],
            exercises: array_map(
                static fn (array $exercise): PlannedExerciseInput => new PlannedExerciseInput(
                    exerciseId: $exercise['exercise_id'],
                    sets: $exercise['sets'],
                    repetitionsPerSet: $exercise['repetitions_per_set'],
                    workingWeightInGrams: WorkingWeightConverter::kilogramsToGrams(
                        $exercise['working_weight_kg'],
                    ),
                ),
                $validated['exercises'],
            ),
        );
    }
}
