<?php

use App\WorkoutAnalysis\Application\Exceptions\AIProviderFailed;
use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalAIConclusion;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendation;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendationResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutHistoryEntry;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Infrastructure\Integrations\OpenAI\OpenAIAnalysisProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('workout-analysis.ai.openai', ['api_key' => 'secret-test-key', 'model' => 'configured-model', 'connect_timeout_seconds' => 5, 'timeout_seconds' => 45, 'max_output_tokens' => 4000]);
    config()->set('workout-analysis.ai.max_input_bytes', 250000);
});

$context = static fn (): AnalysisContextSnapshot => new AnalysisContextSnapshot(
    WorkoutAnalysisFixture::result(),
    new WorkoutHistoryWindow(3, new WorkoutHistoryEntry(
        WorkoutAnalysisFixture::result(sessionId: 50, completedAt: '2026-09-14T12:00:00Z'),
        new HistoricalAIConclusion(new WorkoutAnalysisId(80), new WorkoutSessionId(50), 'Предыдущее заключение.', 'Истории недостаточно.'),
        new HistoricalRecommendationResult(new WorkoutAnalysisId(80), new WorkoutSessionId(50), new HistoricalRecommendation(
            71,
            new ExerciseId(10),
            WorkoutAnalysisFixture::sets([[10, 50000]]),
            'load_change',
            null,
            WorkoutAnalysisFixture::sets([[10, 52500]]),
            'План выполнен.',
            'applied',
            new DateTimeImmutable('2026-09-14T12:01:00Z'),
        )),
    )),
    new WorkoutHistoryWindow(7, new WorkoutHistoryEntry(WorkoutAnalysisFixture::result(sessionId: 49, programId: 12, completedAt: '2026-09-13T12:00:00Z'))),
    new DateTimeImmutable('2026-09-15T12:01:00Z'),
);

$response = static fn (): array => [
    'id' => 'resp_test', 'model' => 'actual-model-version', 'status' => 'completed',
    'output' => [
        ['type' => 'reasoning', 'summary' => []],
        ['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [
            ['type' => 'output_text', 'text' => json_encode([
                'current_workout_fact_ids' => ['current:summary', 'current:exercise:10'],
                'history_fact_ids' => ['history:50:summary', 'history:50:exercise:10'],
            ], JSON_THROW_ON_ERROR)],
        ]],
    ],
];

it('renders selected facts with server units and evidence without publishing model prose', function () use ($context, $response): void {
    Http::fake(['https://api.openai.com/v1/responses' => Http::response($response())]);

    $result = app(OpenAIAnalysisProvider::class)->analyze(new WorkoutAnalysisId(91), $context());

    expect($result->conclusion->currentWorkout)->toContain('Тренировка №51', '10 × 50 кг', 'Без отклонений');
    expect($result->conclusion->history)->toContain('№50 → №51', 'Тренировка №49');
    expect($result->conclusion->evidence[0]->analysisId->value)->toBe(91);
    expect($result->model)->toBe('actual-model-version');
    expect($result->responseId)->toBe('resp_test');
    expect($result->promptVersion)->toBe(2);
    expect($result->schemaVersion)->toBe(2);
    Http::assertSent(function (Request $request): bool {
        expect($request->method())->toBe('POST');
        expect($request->hasHeader('Authorization', 'Bearer secret-test-key'))->toBeTrue();
        expect($request['model'])->toBe('configured-model');
        expect($request['store'])->toBeFalse();
        expect($request['max_output_tokens'])->toBe(4000);
        expect(data_get($request->data(), 'text.format.type'))->toBe('json_schema');
        expect(data_get($request->data(), 'text.format.strict'))->toBeTrue();
        expect(data_get($request->data(), 'text.format.schema.additionalProperties'))->toBeFalse();
        $content = data_get($request->data(), 'input.0.content');
        if (! is_string($content)) {
            throw new LogicException('Отсутствует контекст запроса.');
        }
        $input = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        expect(data_get($input, 'current_workout_session_id'))->toBe(51);
        expect(data_get($input, 'facts.current_workout.current:exercise:10.text'))->toContain('10 × 50 кг');
        expect(data_get($input, 'facts.history.history:50:exercise:10.text'))->toContain('№50', '№51');
        expect(data_get($input, 'facts.history.history:49:summary.text'))->toContain('Другая программа');
        expect(data_get($request->data(), 'text.format.schema.properties.current_workout_fact_ids.items.type'))->toBe('string');
        expect($request->body())->not->toContain('user_id', 'secret-test-key', 'telegram_id', 'actually_used', 'Предыдущее заключение.');

        return true;
    });
    Http::assertSentCount(1);
});

it('rejects an invalid structured conclusion', function (string $json) use ($context, $response): void {
    $payload = $response();
    $payload = array_replace_recursive($payload, ['output' => [1 => ['content' => [0 => ['text' => $json]]]]]);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response($payload)]);

    expect(fn () => app(OpenAIAnalysisProvider::class)->analyze(new WorkoutAnalysisId(91), $context()))
        ->toThrow(new AIProviderFailed(AnalysisFailureCode::InvalidAIResponse));
    Http::assertSentCount(1);
})->with([
    'history fact in current workout' => '{"current_workout_fact_ids":["history:50:exercise:10"],"history_fact_ids":["history:50:summary"]}',
    'invented fact id' => '{"current_workout_fact_ids":["current:exercise:999"],"history_fact_ids":["history:50:summary"]}',
    'empty current facts' => '{"current_workout_fact_ids":[],"history_fact_ids":["history:50:summary"]}',
    'current fact in history' => '{"current_workout_fact_ids":["current:summary"],"history_fact_ids":["current:exercise:10"]}',
    'free text alongside facts' => '{"current_workout_fact_ids":["current:summary"],"history_fact_ids":["history:50:summary"],"current_workout":"Бабочка: один подход."}',
    'broken json' => '{',
    'missing history' => '{"current_workout":"ok","evidence":[]}',
    'blank text' => '{"current_workout":" ","history":"ok","evidence":[]}',
    'nonstring text' => '{"current_workout":5,"history":"ok","evidence":[]}',
    'extra fields' => '{"current_workout":"ok","history":"ok","evidence":[],"recommendations":[]}',
    'object evidence' => '{"current_workout":"ok","history":"ok","evidence":{}}',
    'unknown session' => '{"current_workout":"ok","history":"ok","evidence":[{"workout_session_id":999,"exercise_id":null}]}',
    'unknown exercise' => '{"current_workout":"ok","history":"ok","evidence":[{"workout_session_id":51,"exercise_id":999}]}',
    'coerced id' => '{"current_workout":"ok","history":"ok","evidence":[{"workout_session_id":"51","exercise_id":null}]}',
    'missing nullable key' => '{"current_workout":"ok","history":"ok","evidence":[{"workout_session_id":51}]}',
    'invented analysis id' => '{"current_workout":"ok","history":"ok","evidence":[{"workout_session_id":51,"exercise_id":null,"analysis_id":999}]}',
]);

it('classifies HTTP failures without exposing their body or retrying', function (int $status, AnalysisFailureCode $expected) use ($context): void {
    Http::fake(['https://api.openai.com/v1/responses' => Http::response('secret-provider-body', $status)]);

    expect(function () use ($context): void {
        try {
            app(OpenAIAnalysisProvider::class)->analyze(new WorkoutAnalysisId(91), $context());
        } catch (AIProviderFailed $exception) {
            expect($exception->getMessage())->not->toContain('secret-provider-body', 'secret-test-key');
            expect($exception->getPrevious())->toBeNull();
            throw $exception;
        }
    })->toThrow(new AIProviderFailed($expected));
    Http::assertSentCount(1);
})->with([
    'rate limit' => [429, AnalysisFailureCode::ProviderUnavailable],
    'server error' => [503, AnalysisFailureCode::ProviderUnavailable],
    'bad request' => [400, AnalysisFailureCode::ProviderRejected],
    'authentication' => [401, AnalysisFailureCode::ProviderRejected],
    'redirect' => [302, AnalysisFailureCode::ProviderRejected],
]);

it('rejects malformed JSON and nonobject response bodies', function (string $body) use ($context): void {
    Http::fake(['https://api.openai.com/v1/responses' => Http::response($body)]);

    expect(fn () => app(OpenAIAnalysisProvider::class)->analyze(new WorkoutAnalysisId(91), $context()))
        ->toThrow(new AIProviderFailed(AnalysisFailureCode::InvalidAIResponse));
    Http::assertSentCount(1);
})->with(['broken json' => '{', 'list' => '[]', 'null' => 'null', 'missing fields' => '{}']);

it('classifies connection failures without exposing the original exception', function () use ($context): void {
    Http::fake(['https://api.openai.com/v1/responses' => Http::failedConnection('secret-connection-detail')]);

    expect(function () use ($context): void {
        try {
            app(OpenAIAnalysisProvider::class)->analyze(new WorkoutAnalysisId(91), $context());
        } catch (AIProviderFailed $exception) {
            expect($exception->getMessage())->not->toContain('secret-connection-detail');
            expect($exception->getPrevious())->toBeNull();
            throw $exception;
        }
    })->toThrow(new AIProviderFailed(AnalysisFailureCode::ProviderUnavailable));
});

it('makes no request for invalid configuration or excessive context', function (string $key, mixed $value) use ($context): void {
    config()->set($key, $value);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response()]);

    expect(fn () => app(OpenAIAnalysisProvider::class)->analyze(new WorkoutAnalysisId(91), $context()))
        ->toThrow(new AIProviderFailed(AnalysisFailureCode::ProviderRejected));
    Http::assertNothingSent();
})->with([
    'missing key' => ['workout-analysis.ai.openai.api_key', null],
    'blank model' => ['workout-analysis.ai.openai.model', ' '],
    'invalid timeout' => ['workout-analysis.ai.openai.timeout_seconds', 0],
    'oversized context' => ['workout-analysis.ai.max_input_bytes', 10],
]);

it('rejects refused incomplete or malformed envelopes', function (Closure $modify, AnalysisFailureCode $expected) use ($context, $response): void {
    $payload = $modify($response());
    if (! is_array($payload)) {
        throw new LogicException('Некорректный ответ в тесте.');
    }
    Http::fake(['https://api.openai.com/v1/responses' => Http::response($payload)]);

    expect(fn () => app(OpenAIAnalysisProvider::class)->analyze(new WorkoutAnalysisId(91), $context()))
        ->toThrow(new AIProviderFailed($expected));
    Http::assertSentCount(1);
})->with([
    'incomplete' => [static fn (array $value): array => array_replace($value, ['status' => 'incomplete']), AnalysisFailureCode::IncompleteAIResponse],
    'incomplete without output' => [static fn (array $value): array => array_replace($value, ['status' => 'incomplete', 'output' => null]), AnalysisFailureCode::IncompleteAIResponse],
    'failed' => [static fn (array $value): array => array_replace($value, ['status' => 'failed']), AnalysisFailureCode::InvalidAIResponse],
    'completed with error' => [static fn (array $value): array => array_replace($value, ['error' => ['code' => 'server_error', 'message' => 'secret-provider-error']]), AnalysisFailureCode::InvalidAIResponse],
    'missing model' => [static fn (array $value): array => array_replace($value, ['model' => '']), AnalysisFailureCode::InvalidAIResponse],
    'missing id' => [static fn (array $value): array => array_replace($value, ['id' => null]), AnalysisFailureCode::InvalidAIResponse],
    'missing output' => [static fn (array $value): array => array_replace($value, ['output' => []]), AnalysisFailureCode::InvalidAIResponse],
    'unfinished message' => [static function (array $value): array {
        return array_replace_recursive($value, ['output' => [1 => ['status' => 'in_progress']]]);
    }, AnalysisFailureCode::InvalidAIResponse],
    'multiple texts' => [static function (array $value): array {
        return array_replace_recursive($value, ['output' => [2 => data_get($value, 'output.1')]]);
    }, AnalysisFailureCode::InvalidAIResponse],
    'refusal after text' => [static function (array $value): array {
        return array_replace_recursive($value, ['output' => [1 => ['content' => [1 => ['type' => 'refusal', 'refusal' => 'secret-refusal']]]]]);
    }, AnalysisFailureCode::AIRefused],
]);
