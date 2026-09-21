<?php

use App\WorkoutAnalysis\Application\Exceptions\AIProviderFailed;
use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAIResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Infrastructure\Integrations\OpenAI\OpenAIRecommendationProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('workout-analysis.ai.openai', ['api_key' => 'secret-test-key', 'model' => 'configured-model', 'connect_timeout_seconds' => 5, 'timeout_seconds' => 45, 'max_output_tokens' => 4000]);
    config()->set('workout-analysis.ai.max_input_bytes', 250000);
});

$analysis = static fn (): WorkoutAIResult => new WorkoutAIResult(new WorkoutAnalysisId(91), new AnalysisContextSnapshot(
    Fixture::result(), new WorkoutHistoryWindow(1), new WorkoutHistoryWindow(1), new DateTimeImmutable('2026-09-15T12:01:00Z')),
    'План выполнен.', 'Три успеха подряд.', 'conclusion-model', 'conclusion-response', 1, 1);
$program = static fn (): array => ['program_id' => 11, 'exercises' => [['exercise_id' => 10,
    'sets' => [['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 50000]],
    'successes' => 3, 'failures' => 0, 'completed_since_replacement' => 28, 'completed_since_rejection' => 4, 'currently_successful' => true]],
    'catalog' => [['id' => 10, 'name' => 'Жим'], ['id' => 11, 'name' => 'Другой жим']]];
$proposal = static fn (): array => ['exercise_id' => 10, 'change_type' => 'progression', 'replacement_exercise_id' => null,
    'proposed_sets' => [['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 52500]], 'rationale' => 'Три успеха подряд.',
    'evidence' => [['workout_session_id' => 51, 'exercise_id' => 10]]];
$response = static fn (array $payload): array => ['id' => 'resp-recommendations', 'model' => 'actual-model', 'status' => 'completed',
    'output' => [['type' => 'message', 'role' => 'assistant', 'status' => 'completed',
        'content' => [['type' => 'output_text', 'text' => json_encode($payload, JSON_THROW_ON_ERROR)]]]]];

it('requests separate structured recommendations from the saved conclusion and complete immutable evidence', function () use ($analysis, $program, $proposal, $response): void {
    Http::fake(['https://api.openai.com/v1/responses' => Http::response($response(['proposals' => [$proposal()], 'no_change_reason' => null]))]);
    $batch = app(OpenAIRecommendationProvider::class)->generate($analysis(), $program());
    expect($batch->proposals)->toHaveCount(1);
    expect($batch->proposals[0]->proposedSets->all()[0]->workingWeight->grams)->toBe(52500);
    expect($batch->proposals[0]->evidence[0]->analysisId->value)->toBe(91);
    expect($batch->model)->toBe('actual-model');
    expect($batch->responseId)->toBe('resp-recommendations');
    Http::assertSent(function (Request $request): bool {
        expect($request['store'])->toBeFalse();
        expect(data_get($request->data(), 'text.format.name'))->toBe('workout_recommendations');
        expect(data_get($request->data(), 'text.format.strict'))->toBeTrue();
        $content = data_get($request->data(), 'input.0.content');
        if (! is_string($content)) {
            throw new LogicException('Missing request context.');
        }
        $input = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        expect(data_get($input, 'conclusion.current_workout'))->toBe('План выполнен.');
        expect(data_get($input, 'program.exercises.0.successes'))->toBe(3);
        expect(data_get($input, 'analysis_context.current_workout.snapshot.workout_session_id'))->toBe(51);
        expect($request->body())->not->toContain('user_id', 'telegram_id', 'secret-test-key');

        return true;
    });
    Http::assertSentCount(1);
});

it('preserves an explicit reason for no recommendations', function () use ($analysis, $program, $response): void {
    Http::fake(['https://api.openai.com/v1/responses' => Http::response($response(['proposals' => [], 'no_change_reason' => 'Сохранить план.']))]);
    expect(app(OpenAIRecommendationProvider::class)->generate($analysis(), $program())->noChangeReason)->toBe('Сохранить план.');
    Http::assertSentCount(1);
});

it('rejects invalid proposal payloads without coercing values', function (string $case) use ($analysis, $program, $proposal, $response): void {
    $item = $proposal();
    match ($case) {
        'invented evidence' => $item['evidence'][0]['workout_session_id'] = 999,
        'missing evidence' => $item['evidence'] = [],
        'string id' => $item['exercise_id'] = '10',
        'extra field' => $item['original_sets'] = [],
        'bad position' => $item['proposed_sets'][0]['position'] = 2,
        'zero repetitions' => $item['proposed_sets'][0]['repetitions'] = 0,
        'negative weight' => $item['proposed_sets'][0]['working_weight_grams'] = -1,
        'wrong replacement' => $item['replacement_exercise_id'] = 11,
        'empty rationale' => $item['rationale'] = ' ',
        'unknown type' => $item['change_type'] = 'invented',
        default => throw new LogicException('Unknown invalid payload case.'),
    };
    Http::fake(['https://api.openai.com/v1/responses' => Http::response($response(['proposals' => [$item], 'no_change_reason' => null]))]);
    expect(fn () => app(OpenAIRecommendationProvider::class)->generate($analysis(), $program()))
        ->toThrow(new AIProviderFailed(AnalysisFailureCode::InvalidAIResponse));
    Http::assertSentCount(1);
})->with(['invented evidence', 'missing evidence', 'string id', 'extra field', 'bad position', 'zero repetitions', 'negative weight', 'wrong replacement', 'empty rationale', 'unknown type']);

it('rejects empty proposals without a meaningful reason', function () use ($analysis, $program, $response): void {
    Http::fake(['https://api.openai.com/v1/responses' => Http::response($response(['proposals' => [], 'no_change_reason' => null]))]);
    expect(fn () => app(OpenAIRecommendationProvider::class)->generate($analysis(), $program()))
        ->toThrow(new AIProviderFailed(AnalysisFailureCode::InvalidAIResponse));
    Http::assertSentCount(1);
});

it('classifies provider failures without exposing secrets or retrying', function (int $status, AnalysisFailureCode $failure) use ($analysis, $program): void {
    Http::fake(['https://api.openai.com/v1/responses' => Http::response('secret-provider-body', $status)]);
    try {
        app(OpenAIRecommendationProvider::class)->generate($analysis(), $program());
        throw new LogicException('Expected provider failure.');
    } catch (AIProviderFailed $exception) {
        expect($exception->failureCode)->toBe($failure);
        expect($exception->getMessage())->not->toContain('secret-provider-body', 'secret-test-key');
        expect($exception->getPrevious())->toBeNull();
    }
    Http::assertSentCount(1);
})->with([[429, AnalysisFailureCode::ProviderUnavailable], [503, AnalysisFailureCode::ProviderUnavailable], [401, AnalysisFailureCode::ProviderRejected], [302, AnalysisFailureCode::ProviderRejected]]);

it('sanitizes connection failure and rejects excessive request size before sending', function () use ($analysis, $program): void {
    Http::fake(['https://api.openai.com/v1/responses' => Http::failedConnection('secret-network-detail')]);
    expect(fn () => app(OpenAIRecommendationProvider::class)->generate($analysis(), $program()))
        ->toThrow(new AIProviderFailed(AnalysisFailureCode::ProviderUnavailable));
    config()->set('workout-analysis.ai.max_input_bytes', 10);
    Http::fake();
    expect(fn () => app(OpenAIRecommendationProvider::class)->generate($analysis(), $program()))
        ->toThrow(new AIProviderFailed(AnalysisFailureCode::ProviderRejected));
    Http::assertNothingSent();
});

it('classifies refusal incomplete and malformed envelopes', function (string $case, AnalysisFailureCode $failure) use ($analysis, $program, $response): void {
    $payload = $response(['proposals' => [], 'no_change_reason' => 'Сохранить план.']);
    if ($case === 'refusal') {
        $payload['output'][0]['content'][] = ['type' => 'refusal', 'refusal' => 'provider-secret'];
    } elseif ($case === 'incomplete') {
        $payload['status'] = 'incomplete';
        $payload['output'] = null;
    } else {
        $payload['output'] = [];
    }
    Http::fake(['https://api.openai.com/v1/responses' => Http::response($payload)]);
    expect(fn () => app(OpenAIRecommendationProvider::class)->generate($analysis(), $program()))
        ->toThrow(new AIProviderFailed($failure));
    Http::assertSentCount(1);
})->with([
    ['refusal', AnalysisFailureCode::AIRefused],
    ['incomplete', AnalysisFailureCode::IncompleteAIResponse],
    ['malformed', AnalysisFailureCode::InvalidAIResponse],
]);
