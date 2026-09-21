<?php

namespace App\WorkoutAnalysis\Presentation\Http\Requests;

use App\Models\User;
use App\WorkoutAnalysis\Application\UseCases\ActOnWorkoutRecommendation\ActOnWorkoutRecommendationInput;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

final class ActOnWorkoutRecommendationRequest extends FormRequest
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
            'recommendation_id' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'recommendation_id.required' => 'Укажите рекомендацию.',
            'recommendation_id.integer' => 'Идентификатор рекомендации должен быть целым числом.',
            'recommendation_id.min' => 'Идентификатор рекомендации должен быть положительным числом.',
        ];
    }

    public function toInput(): ActOnWorkoutRecommendationInput
    {
        $user = $this->user();
        if (! $user instanceof User) {
            throw new LogicException('Авторизованный пользователь недоступен.');
        }

        return new ActOnWorkoutRecommendationInput($user->id, $this->integer('recommendation_id'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['recommendation_id' => $this->route('recommendationId')]);
    }
}
