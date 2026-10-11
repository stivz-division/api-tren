<?php

use App\Models\Exercise;
use App\Models\User;
use App\WorkoutAnalysis\Application\UseCases\CalculateWorkoutDeviations\CalculateWorkoutDeviations;
use App\WorkoutAnalysis\Application\UseCases\CalculateWorkoutDeviations\CalculateWorkoutDeviationsInput;
use App\WorkoutAnalysis\Application\UseCases\GenerateWorkoutAIAnalysis\GenerateWorkoutAIAnalysis;
use App\WorkoutAnalysis\Application\UseCases\GenerateWorkoutAIAnalysis\GenerateWorkoutAIAnalysisInput;
use App\WorkoutAnalysis\Application\UseCases\GenerateWorkoutRecommendations\GenerateWorkoutRecommendations;
use App\WorkoutAnalysis\Application\UseCases\GenerateWorkoutRecommendations\GenerateWorkoutRecommendationsInput;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutAnalysisModel;
use App\WorkoutAnalysis\Infrastructure\Queue\CalculateWorkoutDeviationsJob;
use App\WorkoutAnalysis\Infrastructure\Queue\GenerateWorkoutAIAnalysisJob;
use App\WorkoutAnalysis\Infrastructure\Queue\GenerateWorkoutRecommendationsJob;
use App\WorkoutExecution\Application\Gateways\WorkoutClock;
use App\WorkoutExecution\Application\UseCases\StartWorkoutSession\StartWorkoutSession;
use App\WorkoutExecution\Application\UseCases\StartWorkoutSession\StartWorkoutSessionInput;
use App\WorkoutPlanning\Application\UseCases\CreateTrainingProgram\CreateTrainingProgram;
use App\WorkoutPlanning\Application\UseCases\CreateTrainingProgram\CreateTrainingProgramInput;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\TrainingProgramModel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\WorkoutExecution\FrozenWorkoutClock;
use Tests\Support\WorkoutPlanning\PlannedExerciseFixture;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2026-09-21T12:00:00Z'));
    config([
        'workout-execution.mutation_lock.store' => 'array',
        'workout-planning.mutation_lock.store' => 'array',
        'workout-analysis.ai.openai.api_key' => 'replacement-test-key',
        'workout-analysis.ai.openai.model' => 'replacement-test-model',
    ]);
});

/** @return array{user: User, exercises: list<Exercise>, program: TrainingProgramModel, sessionId: int} */
function startWorkoutReplacementScenario(): array
{
    app()->instance(WorkoutClock::class, new FrozenWorkoutClock(now()->toDateTimeImmutable()));
    $user = User::factory()->create();
    $exercises = array_values(Exercise::factory()->count(5)->create()->all());
    $exercises[0]->update(['name' => 'Исходный жим']);
    $exercises[2]->update(['name' => 'Новый жим']);
    $created = app(CreateTrainingProgram::class)->handle(new CreateTrainingProgramInput(
        $user->id, 1, 'Замены', [
            PlannedExerciseFixture::input($exercises[0]->id, 1, 10, 80_000),
            PlannedExerciseFixture::input($exercises[1]->id, 1, 10, 40_000),
        ],
    ));
    $program = TrainingProgramModel::query()->findOrFail($created->id);
    $sessionId = app(StartWorkoutSession::class)->handle(new StartWorkoutSessionInput($user->id, $program->id))->id;
    Sanctum::actingAs($user);

    return compact('user', 'exercises', 'program', 'sessionId');
}

/** @return array{request_id: string, replacement_exercise_id: int, replace_in_plan: bool, sets: list<array{repetitions: int, working_weight_kg: int}>} */
function workoutReplacementPayload(int $candidateId, bool $inPlan, int $request = 1): array
{
    return [
        'request_id' => sprintf('00000000-0000-4000-8000-%012d', $request),
        'replacement_exercise_id' => $candidateId,
        'replace_in_plan' => $inPlan,
        'sets' => [['repetitions' => 8, 'working_weight_kg' => 25]],
    ];
}

function fakeWorkoutReplacementAnalysisRequests(): void
{
    Queue::fake([CalculateWorkoutDeviationsJob::class, GenerateWorkoutAIAnalysisJob::class, GenerateWorkoutRecommendationsJob::class]);
    Http::preventStrayRequests();
    Http::fake(['https://api.openai.com/v1/responses' => function (Request $request) {
        $content = data_get($request->data(), 'input.0.content');
        if (! is_string($content)) {
            throw new LogicException('Missing analysis request context.');
        }
        $input = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        $currentFacts = data_get($input, 'facts.current_workout');
        $historyFacts = data_get($input, 'facts.history');
        if (data_get($request->data(), 'text.format.name') === 'workout_analysis') {
            if (! is_array($currentFacts) || ! is_array($historyFacts)) {
                throw new LogicException('Missing server facts.');
            }
            $payload = [
                'current_workout_fact_ids' => array_keys($currentFacts),
                'history_fact_ids' => array_keys($historyFacts),
            ];
        } else {
            $payload = ['proposals' => [], 'no_change_reason' => 'Сохранить план.'];
        }

        return Http::response([
            'id' => 'replacement-response', 'model' => 'replacement-test-model', 'status' => 'completed',
            'output' => [['type' => 'message', 'role' => 'assistant', 'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => json_encode($payload, JSON_THROW_ON_ERROR)]]]],
        ]);
    }]);
}

function processWorkoutReplacementAnalysis(int $userId, int $sessionId): void
{
    $analysisId = WorkoutAnalysisModel::query()->where('workout_session_id', $sessionId)->sole()->id;
    Queue::assertPushed(CalculateWorkoutDeviationsJob::class, fn (CalculateWorkoutDeviationsJob $job): bool => $job->analysisId === $analysisId && $job->userId === $userId);
    app(CalculateWorkoutDeviations::class)->handle(new CalculateWorkoutDeviationsInput($userId, $analysisId, 1));
    app(GenerateWorkoutAIAnalysis::class)->handle(new GenerateWorkoutAIAnalysisInput($userId, $analysisId, 1));
    app(GenerateWorkoutRecommendations::class)->handle(new GenerateWorkoutRecommendationsInput($userId, $analysisId, 1));
    Queue::assertPushed(GenerateWorkoutAIAnalysisJob::class, 1);
    Queue::assertPushed(GenerateWorkoutRecommendationsJob::class, 1);
}

it('completes replacement through HTTP and preserves comparison and history after source deletion', function (bool $inPlan): void {
    fakeWorkoutReplacementAnalysisRequests();
    ['user' => $user, 'exercises' => $exercises, 'program' => $program, 'sessionId' => $sessionId] = startWorkoutReplacementScenario();
    $sourceId = $exercises[0]->id;
    $candidateId = $exercises[2]->id;
    $base = "/api/workout-sessions/{$sessionId}/exercises/{$sourceId}";
    $this->putJson($base.'/sets', ['sets' => [['repetitions' => 3, 'working_weight_kg' => 99]]])->assertOk();

    $this->postJson($base.'/replace', workoutReplacementPayload($candidateId, $inPlan))
        ->assertOk()->assertJsonPath('data.exercises.0.status', 'pending')
        ->assertJsonPath('data.exercises.0.sets.0.working_weight_kg', 25);
    $this->postJson($base.'/complete', ['sets' => [['repetitions' => 9, 'working_weight_kg' => 30]]])->assertOk();
    $this->postJson("/api/workout-sessions/{$sessionId}/exercises/{$exercises[1]->id}/complete", [
        'sets' => [['repetitions' => 10, 'working_weight_kg' => 40]],
    ])->assertOk();
    $this->postJson("/api/workout-sessions/{$sessionId}/complete")->assertOk();
    processWorkoutReplacementAnalysis($user->id, $sessionId);

    $response = $this->getJson("/api/workout-sessions/{$sessionId}/analysis")->assertOk()
        ->assertJsonPath('data.overall_status', 'completed')
        ->assertJsonPath('data.result.excluded_exercises_count', $inPlan ? 0 : 1)
        ->assertJsonPath('data.result.skipped_exercises', 0)
        ->assertJsonPath('data.result.volume_kg.planned', $inPlan ? 600 : 400)
        ->assertJsonPath('data.result.volume_kg.actual', $inPlan ? 670 : 400)
        ->assertJsonCount($inPlan ? 2 : 1, 'data.result.exercises');
    if ($inPlan) {
        $response->assertJsonPath('data.result.exercises.0.exercise_id', $candidateId)
            ->assertJsonPath('data.result.exercises.0.planned_sets.0.working_weight_kg', 25)
            ->assertJsonPath('data.result.exercises.0.actual_sets.0.working_weight_kg', 30);
    } else {
        $response->assertJsonPath('data.result.exercises.0.exercise_id', $exercises[1]->id);
    }
    expect($program->plannedExercises()->where('position', 1)->sole()->exercise_id)->toBe($inPlan ? $candidateId : $sourceId);
    Http::assertSentCount(2);
    foreach (Http::recorded() as [$request]) {
        $content = data_get($request->data(), 'input.0.content');
        if (! is_string($content)) {
            throw new LogicException('Missing analysis request context.');
        }
        $input = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        $performedData = json_encode([
            'facts' => data_get($input, 'facts'),
            'analysis_context' => data_get($input, 'analysis_context'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach (['80000', '99000'] as $excludedLoad) {
            expect($content)->not->toContain($excludedLoad);
        }
        expect($performedData)->not->toContain('Исходный жим');
        if (! $inPlan) {
            foreach (['25000', '30000'] as $excludedLoad) {
                expect($content)->not->toContain($excludedLoad);
            }
            expect($performedData)->not->toContain('Новый жим');
        }
    }
    $program->delete();
    $exercises[0]->delete();
    $exercises[2]->delete();
    $this->getJson('/api/workout-sessions')->assertOk()
        ->assertJsonPath('data.0.exercises.0.exercise_id', $sourceId)
        ->assertJsonPath('data.0.exercises.0.name', 'Исходный жим')
        ->assertJsonPath('data.0.exercises.0.effective_exercise.exercise_id', $candidateId)
        ->assertJsonPath('data.0.exercises.0.effective_exercise.name', 'Новый жим')
        ->assertJsonPath('data.0.exercises.0.sets.0.working_weight_kg', 30);
    $originalAnalysis = $response->json();
    if (! is_array($originalAnalysis)) {
        throw new LogicException('Missing original analysis response.');
    }
    $this->getJson("/api/workout-sessions/{$sessionId}/analysis")->assertOk()->assertExactJson($originalAnalysis);
})->with(['temporary' => false, 'permanent' => true]);

it('uses the final mode and latest permanent baseline in mixed replacement chains', function (array $flags, int $baselineCandidate, bool $included): void {
    fakeWorkoutReplacementAnalysisRequests();
    ['user' => $user, 'exercises' => $exercises, 'program' => $program, 'sessionId' => $sessionId] = startWorkoutReplacementScenario();
    $base = "/api/workout-sessions/{$sessionId}/exercises/{$exercises[0]->id}";
    foreach ($flags as $index => $flag) {
        if (! is_bool($flag)) {
            throw new LogicException('Replacement mode must be boolean.');
        }
        $this->postJson($base.'/replace', workoutReplacementPayload($exercises[$index + 2]->id, $flag, $index + 1))
            ->assertOk()->assertJsonPath('data.exercises.0.included_in_analysis', $flag);
    }
    $this->postJson($base.'/complete', ['sets' => [['repetitions' => 8, 'working_weight_kg' => 25]]])->assertOk();
    $this->postJson("/api/workout-sessions/{$sessionId}/exercises/{$exercises[1]->id}/skip")->assertOk();
    $this->postJson("/api/workout-sessions/{$sessionId}/complete")->assertOk();
    processWorkoutReplacementAnalysis($user->id, $sessionId);

    expect($program->plannedExercises()->where('position', 1)->sole()->exercise_id)->toBe($exercises[$baselineCandidate]->id);
    $response = $this->getJson("/api/workout-sessions/{$sessionId}/analysis")->assertOk()
        ->assertJsonPath('data.overall_status', 'completed')
        ->assertJsonPath('data.result.excluded_exercises_count', $included ? 0 : 1)
        ->assertJsonPath('data.result.volume_kg.planned', $included ? 600 : 400);
    if ($included) {
        $response->assertJsonPath('data.result.exercises.0.exercise_id', $exercises[count($flags) + 1]->id)
            ->assertJsonPath('data.result.exercises.0.plan_fulfilled', true);
    }
    Http::assertSentCount(2);
})->with([
    'temporary then permanent' => [[false, true], 3, true],
    'permanent then temporary' => [[true, false], 2, false],
    'permanent temporary permanent' => [[true, false, true], 4, true],
]);

it('completes all temporarily replaced positions with no comparable data and no recommendation HTTP request', function (): void {
    fakeWorkoutReplacementAnalysisRequests();
    ['user' => $user, 'exercises' => $exercises, 'sessionId' => $sessionId] = startWorkoutReplacementScenario();
    foreach ([0, 1] as $index) {
        $base = "/api/workout-sessions/{$sessionId}/exercises/{$exercises[$index]->id}";
        $this->postJson($base.'/replace', workoutReplacementPayload($exercises[$index + 2]->id, false))->assertOk();
        $this->postJson($base.'/complete', ['sets' => [['repetitions' => 8, 'working_weight_kg' => 25]]])->assertOk();
    }
    $this->postJson("/api/workout-sessions/{$sessionId}/complete")->assertOk();
    processWorkoutReplacementAnalysis($user->id, $sessionId);

    $this->getJson("/api/workout-sessions/{$sessionId}/analysis")->assertOk()
        ->assertJsonPath('data.overall_status', 'completed')
        ->assertJsonPath('data.result.has_comparable_exercises', false)
        ->assertJsonPath('data.result.excluded_exercises_count', 2)
        ->assertJsonPath('data.result.completed_exercises', 0)
        ->assertJsonPath('data.result.skipped_exercises', 0)
        ->assertJsonPath('data.result.exercises', [])
        ->assertJsonPath('data.result.volume_kg.planned', 0)
        ->assertJsonPath('data.result.volume_kg.actual', 0)
        ->assertJsonPath('data.recommendation_generation.items', [])
        ->assertJsonPath('data.recommendation_generation.no_change_reason', 'Нет упражнений для сравнения в текущей тренировке');
    Http::assertSentCount(1);
    $this->getJson('/api/workout-sessions')->assertOk()->assertJsonCount(2, 'data.0.exercises');
});

it('replays current state after fact another replacement and completion and rejects changed UUID payloads', function (): void {
    Queue::fake([CalculateWorkoutDeviationsJob::class, GenerateWorkoutAIAnalysisJob::class, GenerateWorkoutRecommendationsJob::class]);
    Http::preventStrayRequests();
    ['exercises' => $exercises, 'sessionId' => $sessionId] = startWorkoutReplacementScenario();
    $base = "/api/workout-sessions/{$sessionId}/exercises/{$exercises[0]->id}";
    $first = workoutReplacementPayload($exercises[2]->id, false);
    $this->postJson($base.'/replace', $first)->assertOk();
    $progress = ['sets' => [['repetitions' => 7, 'working_weight_kg' => 30]]];
    $this->putJson($base.'/sets', $progress)->assertOk();
    $this->postJson($base.'/replace', $first)->assertOk()->assertJsonPath('data.exercises.0.sets.0.repetitions', 7);
    $second = workoutReplacementPayload($exercises[3]->id, true, 2);
    $this->postJson($base.'/replace', $second)->assertOk();
    $this->postJson($base.'/complete', $progress)->assertOk();
    $this->postJson($base.'/replace', $first)->assertOk()
        ->assertJsonPath('data.exercises.0.effective_exercise.exercise_id', $exercises[3]->id)
        ->assertJsonPath('data.exercises.0.status', 'completed')
        ->assertJsonPath('data.exercises.0.sets.0.repetitions', 7);
    $this->postJson("/api/workout-sessions/{$sessionId}/exercises/{$exercises[1]->id}/skip")->assertOk();
    $this->postJson("/api/workout-sessions/{$sessionId}/complete")->assertOk();

    $this->postJson($base.'/replace', $first)->assertOk()->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.exercises.0.effective_exercise.exercise_id', $exercises[3]->id);
    $this->postJson($base.'/replace', $second)->assertOk()->assertJsonPath('data.exercises.0.sets.0.repetitions', 7);
    foreach ([['replacement_exercise_id' => $exercises[4]->id], ['replace_in_plan' => true], ['sets' => [['repetitions' => 9, 'working_weight_kg' => 25]]]] as $changes) {
        $this->postJson($base.'/replace', [...$first, ...$changes])->assertConflict()
            ->assertJsonPath('code', 'workout_replacement_request_conflict');
    }
    $this->postJson($base.'/replace', workoutReplacementPayload($exercises[4]->id, false, 3))->assertConflict()
        ->assertJsonPath('code', 'workout_session_not_in_progress');
    $this->assertDatabaseCount('workout_exercise_replacements', 2);
    $this->assertDatabaseCount('workout_analyses', 1);
    Queue::assertPushed(CalculateWorkoutDeviationsJob::class, 1);
    Http::assertNothingSent();
});
