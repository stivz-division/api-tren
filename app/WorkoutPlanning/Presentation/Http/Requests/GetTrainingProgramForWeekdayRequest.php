<?php

namespace App\WorkoutPlanning\Presentation\Http\Requests;

use App\Models\User;
use App\WorkoutPlanning\Application\UseCases\GetTrainingProgramForWeekday\GetTrainingProgramForWeekdayInput;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

final class GetTrainingProgramForWeekdayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'weekday' => ['required', 'integer', 'between:1,7'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'weekday.required' => 'Укажите день недели.',
            'weekday.integer' => 'День недели должен быть целым числом.',
            'weekday.between' => 'День недели должен быть числом от 1 до 7.',
        ];
    }

    public function toInput(): GetTrainingProgramForWeekdayInput
    {
        $user = $this->user();

        if (! $user instanceof User) {
            throw new LogicException('Авторизованный пользователь недоступен.');
        }

        return new GetTrainingProgramForWeekdayInput(
            userId: $user->id,
            weekday: $this->integer('weekday'),
        );
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'weekday' => $this->route('weekday'),
        ]);
    }
}
