<?php

namespace App\WorkoutExecution\Presentation\Http\Requests;

use App\WorkoutExecution\Application\UseCases\GetWorkoutSessionHistory\GetWorkoutSessionHistoryInput;
use Closure;
use DateTimeImmutable;
use Illuminate\Pagination\Cursor;

final class GetWorkoutSessionHistoryRequest extends AuthenticatedWorkoutRequest
{
    /** @return array<string, list<Closure|string>> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'cursor' => [
                'sometimes',
                'nullable',
                'string',
                'max:2048',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && ! self::isValidCursor($value)) {
                        $fail('Курсор истории тренировок недействителен.');
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'per_page.integer' => 'Размер страницы должен быть целым числом.',
            'per_page.min' => 'Размер страницы должен быть не меньше 1.',
            'per_page.max' => 'Размер страницы не может превышать 50.',
            'cursor.string' => 'Курсор должен быть строкой.',
            'cursor.max' => 'Курсор имеет недопустимую длину.',
        ];
    }

    public function toInput(): GetWorkoutSessionHistoryInput
    {
        $cursor = $this->input('cursor');

        return new GetWorkoutSessionHistoryInput(
            userId: $this->authenticatedUserId(),
            perPage: $this->integer('per_page', 15),
            cursor: is_string($cursor) ? $cursor : null,
        );
    }

    private static function isValidCursor(string $encodedCursor): bool
    {
        $cursor = Cursor::fromEncoded($encodedCursor);

        if ($cursor === null) {
            return false;
        }

        $parameters = $cursor->toArray();

        return count($parameters) === 3
            && self::isValidStartedAt($parameters['started_at'] ?? null)
            && is_int($parameters['id'] ?? null)
            && $parameters['id'] > 0
            && is_bool($parameters['_pointsToNextItems'] ?? null);
    }

    private static function isValidStartedAt(mixed $startedAt): bool
    {
        if (! is_string($startedAt)) {
            return false;
        }

        if (preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $startedAt) !== 1) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $startedAt);

        return $date !== false && $date->format('Y-m-d H:i:s') === $startedAt;
    }
}
