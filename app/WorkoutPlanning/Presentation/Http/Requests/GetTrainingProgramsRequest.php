<?php

namespace App\WorkoutPlanning\Presentation\Http\Requests;

use App\Models\User;
use App\WorkoutPlanning\Application\UseCases\GetTrainingPrograms\GetTrainingProgramsInput;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

final class GetTrainingProgramsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    public function toInput(): GetTrainingProgramsInput
    {
        $user = $this->user();

        if (! $user instanceof User) {
            throw new LogicException('Авторизованный пользователь недоступен.');
        }

        return new GetTrainingProgramsInput(userId: $user->id);
    }
}
