<?php

use App\WorkoutAnalysis\Application\Exceptions\AIProviderFailed;
use App\WorkoutAnalysis\Domain\Collections\ExercisePerformanceCollection;
use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\ExerciseCompletionStatus;
use App\WorkoutAnalysis\Domain\Services\RecommendationAdmissionPolicy;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisEvidenceReference;
use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExcludedExerciseSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\ExercisePosition;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationBatch;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationProposal;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAIResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutHistoryEntry;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Infrastructure\Integrations\OpenAI\OpenAIRecommendationProvider;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\AnalysisPayload;
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

it('sends server allowed change types for the first workout and preserves the frozen program', function (string $performance, array $allowed) use ($program, $response): void {
    $exercise = Fixture::exercise(actual: $performance === 'skipped' ? [] : [[$performance === 'success' ? 12 : 8, 50000]],
        status: $performance === 'skipped' ? ExerciseCompletionStatus::Skipped : ExerciseCompletionStatus::Completed);
    $analysis = new WorkoutAIResult(new WorkoutAnalysisId(91), new AnalysisContextSnapshot(
        Fixture::result(exercise: $exercise), new WorkoutHistoryWindow, new WorkoutHistoryWindow, new DateTimeImmutable('2026-09-15T12:01:00Z')),
        'Первая тренировка.', 'Истории нет.', 'model', 'response', 1, 1);
    $context = $program();
    $context['exercises'][0]['successes'] = $performance === 'success' ? 1 : 0;
    $context['exercises'][0]['failures'] = $performance === 'success' ? 0 : 1;
    $context['exercises'][0]['currently_successful'] = $performance === 'success';
    $context['exercises'][0]['completed_since_replacement'] = 1;
    Http::fake(['https://api.openai.com/v1/responses' => Http::response($response(['proposals' => [], 'no_change_reason' => 'Сохранить вес.']))]);

    $batch = app(OpenAIRecommendationProvider::class)->generate($analysis, $context);

    expect($batch->promptVersion)->toBe(3);
    expect($context['exercises'][0])->not->toHaveKey('allowed_change_types');
    Http::assertSent(function (Request $request) use ($allowed): bool {
        $content = data_get($request->data(), 'input.0.content');
        if (! is_string($content)) {
            throw new LogicException('Missing request context.');
        }
        $input = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        expect(data_get($input, 'program.exercises.0.allowed_change_types'))->toBe($allowed);
        expect(data_get($input, 'program.exercises.0.sets.0.working_weight_grams'))->toBe(50000);

        return true;
    });
    Http::assertSentCount(1);
})->with([
    'first success' => ['success', ['progression']],
    'first deficit' => ['deficit', ['adjustment']],
    'skipped exercise' => ['skipped', []],
]);

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

it('removes excluded source loads from both saved snapshots and program payload while retaining occupied ids', function () use ($program, $response): void {
    $base = Fixture::result()->snapshot;
    $makeSnapshot = static fn (int $id, string $completedAt): CompletedWorkoutSnapshot => new CompletedWorkoutSnapshot(
        new WorkoutSessionId($id),
        $base->userId,
        $base->trainingProgramId,
        $base->programName,
        new DateTimeImmutable($completedAt),
        new ExercisePerformanceCollection(
            Fixture::exercise(id: 20, position: 2, planned: [[8, 20000]], actual: [[8, 20000]]),
        ),
        [new ExcludedExerciseSnapshot(new ExerciseId(10), new ExerciseId(10), new ExercisePosition(1))],
    );
    $calculator = new WorkoutDeviationCalculator;
    $context = new AnalysisContextSnapshot(
        $calculator->calculate($makeSnapshot(51, '2026-09-15T12:00:00Z')),
        new WorkoutHistoryWindow(3, new WorkoutHistoryEntry($calculator->calculate($makeSnapshot(50, '2026-09-14T12:00:00Z')))),
        new WorkoutHistoryWindow, new DateTimeImmutable('2026-09-15T12:01:00Z'),
    );
    $analysis = new WorkoutAIResult(new WorkoutAnalysisId(91), $context, 'Учитываемая часть тренировки.', 'История.', 'model', 'response', 2, 2);
    $programContext = $program();
    $included = $programContext['exercises'][0];
    $included['exercise_id'] = 20;
    $included['sets'] = [['position' => 1, 'repetitions' => 8, 'working_weight_grams' => 20000]];
    $programContext['exercises'][] = $included;
    $programContext['catalog'][] = ['id' => 20, 'name' => 'Учитываемое упражнение'];
    Http::fake(['https://api.openai.com/v1/responses' => Http::response($response(['proposals' => [], 'no_change_reason' => 'Сохранить план.']))]);

    app(OpenAIRecommendationProvider::class)->generate($analysis, $programContext);

    expect($programContext['exercises'][0]['sets'][0]['working_weight_grams'])->toBe(50000);
    Http::assertSent(function (Request $request): bool {
        $content = data_get($request->data(), 'input.0.content');
        if (! is_string($content)) {
            throw new LogicException('Missing context.');
        }
        $input = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        expect(array_column(AnalysisPayload::list(data_get($input, 'program.exercises')), 'exercise_id'))->toBe([10, 20]);
        expect(data_get($input, 'program.exercises.0.sets'))->toBe([]);
        expect(data_get($input, 'program.exercises.0.allowed_change_types'))->toBe([]);
        expect(data_get($input, 'program.exercises.1.allowed_change_types'))->toBe(['progression', 'replacement']);
        expect(array_column(AnalysisPayload::list(data_get($input, 'program.catalog')), 'id'))->toContain(11);
        expect(array_column(AnalysisPayload::list(data_get($input, 'analysis_context.current_workout.snapshot.exercises')), 'exercise_id'))->toBe([20]);
        expect(array_column(AnalysisPayload::list(data_get($input, 'analysis_context.same_program.entries.0.snapshot.exercises')), 'exercise_id'))->toBe([20]);
        foreach (['50000', '99000'] as $excludedLoad) {
            expect($content)->not->toContain($excludedLoad);
        }
        $analysisContext = json_encode(data_get($input, 'analysis_context'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        expect($analysisContext)->not->toContain('Разовая замена');

        return true;
    });
    Http::assertSentCount(1);
    $proposal = new RecommendationProposal(20, 'replacement', 10, Fixture::sets([[8, 20000]]), 'Другая нагрузка.',
        new AnalysisEvidenceReference(new WorkoutAnalysisId(91), new WorkoutSessionId(51), new ExerciseId(20)));
    $admitted = (new RecommendationAdmissionPolicy)->admit(new RecommendationBatch([$proposal]), $analysis, $programContext);
    expect($admitted->proposals)->toBe([]);
    expect($admitted->rejectedReasons)->toBe(['20:replacement_conflict']);
});
