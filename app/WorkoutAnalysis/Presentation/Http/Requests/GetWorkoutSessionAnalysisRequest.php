<?php

namespace App\WorkoutAnalysis\Presentation\Http\Requests;

use App\Models\User;
use App\WorkoutAnalysis\Application\UseCases\GetWorkoutSessionAnalysis\GetWorkoutSessionAnalysisInput;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

final class GetWorkoutSessionAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            /** @ignoreParam */
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

    public function toInput(): GetWorkoutSessionAnalysisInput
    {
        $user = $this->user();
        if (! $user instanceof User) {
            throw new LogicException('Авторизованный пользователь недоступен.');
        }

        return new GetWorkoutSessionAnalysisInput($user->id, $this->integer('workout_session_id'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['workout_session_id' => $this->route('workoutSessionId')]);
    }
}
