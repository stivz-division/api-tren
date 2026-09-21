<?php

use App\Models\User;
use App\WorkoutAnalysis\Application\Exceptions\AIProviderFailed;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Factories\CompletedWorkoutSnapshotFactory;
use App\WorkoutAnalysis\Application\Gateways\AIProvider;
use App\WorkoutAnalysis\Application\Gateways\CompletedWorkoutProvider;
use App\WorkoutAnalysis\Application\Gateways\WorkoutHistoryProvider;
use App\WorkoutAnalysis\Application\UseCases\CalculateWorkoutDeviations\CalculateWorkoutDeviations;
use App\WorkoutAnalysis\Application\UseCases\CalculateWorkoutDeviations\CalculateWorkoutDeviationsInput;
use App\WorkoutAnalysis\Application\UseCases\GenerateWorkoutAIAnalysis\GenerateWorkoutAIAnalysis;
use App\WorkoutAnalysis\Application\UseCases\GenerateWorkoutAIAnalysis\GenerateWorkoutAIAnalysisInput;
use App\WorkoutAnalysis\Application\UseCases\PrepareWorkoutAnalysisContext\PrepareWorkoutAnalysisContext;
use App\WorkoutAnalysis\Application\UseCases\PrepareWorkoutAnalysisContext\PrepareWorkoutAnalysisContextInput;
use App\WorkoutAnalysis\Application\UseCases\RecoverWorkoutAIAnalysis\RecoverWorkoutAIAnalysis;
use App\WorkoutAnalysis\Application\UseCases\RecoverWorkoutAIAnalysis\RecoverWorkoutAIAnalysisInput;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAIResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutDeviationResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutAIAnalysisModel;
use App\WorkoutAnalysis\Infrastructure\Queue\GenerateWorkoutAIAnalysisJob;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSessionModel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2026-09-21T12:00:00Z'));
    Queue::fake();
});

$pendingAI = static function (?User $user = null, ?WorkoutDeviationResult $result = null): WorkoutAnalysis {
    $user ??= User::factory()->create();
    $result ??= WorkoutAnalysisFixture::result(userId: $user->id);
    $now = new DateTimeImmutable('2026-09-20T12:00:00Z');
    $analysis = WorkoutAnalysis::initialize($result->snapshot, $now);
    $analysis->startDeviationAttempt(1, $now, $now->modify('+1 minute'));
    $analysis->completeDeviationAttempt(1, $result, $now->modify('+1 second'));
    $analysis->scheduleAI($now->modify('+1 second'));

    return DB::transaction(fn () => app(WorkoutAnalysisRepository::class)->add($analysis));
};

it('calls the provider outside the database transaction and persists one conclusion for duplicate delivery', function () use ($pendingAI): void {
    $analysis = $pendingAI();
    $id = $analysis->id ?? throw new LogicException('Отсутствует анализ.');
    $userId = $analysis->deviations()->snapshot->userId;
    $this->mock(AIProvider::class)->shouldReceive('analyze')->once()->andReturnUsing(function (WorkoutAnalysisId $id, AnalysisContextSnapshot $context): WorkoutAIResult {
        expect(DB::transactionLevel())->toBe(0);

        return new WorkoutAIResult($id, $context, 'План выполнен.', 'Истории пока нет.', 'test-model', 'resp_1', 1, 1);
    });
    $input = new GenerateWorkoutAIAnalysisInput($userId->value, $id->value, 1);

    app(GenerateWorkoutAIAnalysis::class)->handle($input);
    app(GenerateWorkoutAIAnalysis::class)->handle($input);

    $stored = app(WorkoutAnalysisRepository::class)->findForUser($id, $userId);
    expect($stored?->ai()?->status())->toBe(AnalysisStatus::Completed);
    expect($stored?->ai()?->result?->conclusion->currentWorkout)->toBe('План выполнен.');
    expect($stored?->context())->not->toBeNull();
    $this->assertDatabaseCount('workout_ai_attempts', 1);
});

it('retries transient failures with the frozen context and ignores early delivery', function () use ($pendingAI): void {
    $analysis = $pendingAI();
    $id = $analysis->id ?? throw new LogicException('Отсутствует анализ.');
    $userId = $analysis->deviations()->snapshot->userId;
    $this->mock(AIProvider::class)->shouldReceive('analyze')->once()->andThrow(new AIProviderFailed(AnalysisFailureCode::ProviderUnavailable));
    app(GenerateWorkoutAIAnalysis::class)->handle(new GenerateWorkoutAIAnalysisInput($userId->value, $id->value, 1));
    $context = app(WorkoutAnalysisRepository::class)->findForUser($id, $userId)?->context();
    Queue::assertPushed(GenerateWorkoutAIAnalysisJob::class, fn (GenerateWorkoutAIAnalysisJob $job): bool => $job->attemptNumber === 2 && $job->delay instanceof DateTimeInterface && $job->delay->getTimestamp() === now()->addSeconds(5)->getTimestamp());
    app(GenerateWorkoutAIAnalysis::class)->handle(new GenerateWorkoutAIAnalysisInput($userId->value, $id->value, 2));
    $this->travel(5)->seconds();
    config()->set('workout-analysis.history.same_program_limit', 1);
    $this->mock(WorkoutHistoryProvider::class)->shouldNotReceive('read');
    $this->mock(AIProvider::class)->shouldReceive('analyze')->once()->andReturnUsing(function (WorkoutAnalysisId $id, AnalysisContextSnapshot $actual) use ($context): WorkoutAIResult {
        expect($actual)->toEqual($context);

        return new WorkoutAIResult($id, $actual, 'План выполнен.', 'Истории нет.', 'test-model', 'resp_2', 1, 1);
    });

    $stage = app(GenerateWorkoutAIAnalysis::class)->handle(new GenerateWorkoutAIAnalysisInput($userId->value, $id->value, 2));

    expect($stage?->status())->toBe(AnalysisStatus::Completed);
    expect($stage?->attempts())->toHaveCount(2);
    expect($stage?->attempts()[0]->failureCode)->toBe(AnalysisFailureCode::ProviderUnavailable);
});

it('ends automatic retries at the configured limit and supports a manual retry', function () use ($pendingAI): void {
    $analysis = $pendingAI();
    $id = $analysis->id ?? throw new LogicException('Отсутствует анализ.');
    $userId = $analysis->deviations()->snapshot->userId;
    $this->mock(AIProvider::class)->shouldReceive('analyze')->times(3)->andThrow(new AIProviderFailed(AnalysisFailureCode::ProviderUnavailable));
    foreach ([1 => 0, 2 => 5, 3 => 30] as $attempt => $delay) {
        $this->travel($delay)->seconds();
        app(GenerateWorkoutAIAnalysis::class)->handle(new GenerateWorkoutAIAnalysisInput($userId->value, $id->value, $attempt));
    }
    $stored = app(WorkoutAnalysisRepository::class)->findForUser($id, $userId);
    expect($stored?->ai()?->status())->toBe(AnalysisStatus::Failed);
    expect($stored?->ai()?->currentAttempt()->number)->toBe(3);
    Queue::assertPushed(GenerateWorkoutAIAnalysisJob::class, 2);

    expect(Artisan::call('workout-analysis:retry-ai', ['analysisId' => $id->value]))->toBe(0);

    $retried = app(WorkoutAnalysisRepository::class)->findForUser($id, $userId);
    expect($retried?->ai()?->currentAttempt()->number)->toBe(4);
    expect($retried?->ai()?->currentAttempt()->cycleAttempt)->toBe(1);
    expect($retried?->context())->toEqual($stored?->context());
});

it('publishes a permanent AI failure without hiding the completed deviations', function (AnalysisFailureCode $failure) use ($pendingAI): void {
    $analysis = $pendingAI();
    $id = $analysis->id ?? throw new LogicException('Отсутствует анализ.');
    $userId = $analysis->deviations()->snapshot->userId;
    $this->mock(AIProvider::class)->shouldReceive('analyze')->once()->andThrow(new AIProviderFailed($failure));

    $stage = app(GenerateWorkoutAIAnalysis::class)->handle(new GenerateWorkoutAIAnalysisInput($userId->value, $id->value, 1));

    expect($stage?->status())->toBe(AnalysisStatus::Failed);
    expect($stage?->currentAttempt()->failureCode)->toBe($failure);
    Queue::assertNothingPushed();
    Sanctum::actingAs(User::query()->findOrFail($userId->value));
    $this->getJson('/api/workout-sessions/51/analysis')->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.ai_analysis.status', 'failed')
        ->assertJsonPath('data.ai_analysis.failure_code', $failure->value)
        ->assertJsonPath('data.ai_analysis.result', null);
})->with([AnalysisFailureCode::AIRefused, AnalysisFailureCode::InvalidAIResponse, AnalysisFailureCode::IncompleteAIResponse, AnalysisFailureCode::ProviderRejected]);

it('rejects a late provider response after recovery has replaced the attempt', function () use ($pendingAI): void {
    $analysis = $pendingAI();
    $id = $analysis->id ?? throw new LogicException('Отсутствует анализ.');
    $userId = $analysis->deviations()->snapshot->userId;
    $this->mock(AIProvider::class)->shouldReceive('analyze')->once()->andReturnUsing(function (WorkoutAnalysisId $id, AnalysisContextSnapshot $context): WorkoutAIResult {
        $this->travel(121)->seconds();
        app(RecoverWorkoutAIAnalysis::class)->handle(new RecoverWorkoutAIAnalysisInput($context->currentWorkout->snapshot->userId->value, 51));

        return new WorkoutAIResult($id, $context, 'Запоздалый результат.', 'Истории нет.', 'test-model', 'resp_late', 1, 1);
    });

    $stage = app(GenerateWorkoutAIAnalysis::class)->handle(new GenerateWorkoutAIAnalysisInput($userId->value, $id->value, 1));

    expect($stage?->status())->toBe(AnalysisStatus::Pending);
    expect($stage?->currentAttempt()->number)->toBe(2);
    expect($stage?->result)->toBeNull();
    expect($stage?->attempts()[0]->failureCode)->toBe(AnalysisFailureCode::AttemptTimedOut);
});

it('records context preparation failure without sending a request', function () use ($pendingAI): void {
    $analysis = $pendingAI();
    $id = $analysis->id ?? throw new LogicException('Отсутствует анализ.');
    $userId = $analysis->deviations()->snapshot->userId;
    $this->mock(WorkoutHistoryProvider::class)->shouldReceive('read')->once()->andThrow(new RuntimeException('Storage unavailable'));
    $this->mock(AIProvider::class)->shouldNotReceive('analyze');

    $stage = app(GenerateWorkoutAIAnalysis::class)->handle(new GenerateWorkoutAIAnalysisInput($userId->value, $id->value, 1));

    expect($stage?->currentAttempt()->failureCode)->toBe(AnalysisFailureCode::ContextPreparationFailed);
    expect(app(WorkoutAnalysisRepository::class)->findForUser($id, $userId)?->context())->toBeNull();
});

it('enqueues the AI stage atomically with successful deviations', function (): void {
    $user = User::factory()->create();
    $result = WorkoutAnalysisFixture::result(userId: $user->id);
    $analysis = DB::transaction(fn () => app(WorkoutAnalysisRepository::class)->add(WorkoutAnalysis::initialize($result->snapshot, now()->toDateTimeImmutable())));
    $id = $analysis->id ?? throw new LogicException('Отсутствует анализ.');

    app(CalculateWorkoutDeviations::class)->handle(new CalculateWorkoutDeviationsInput($user->id, $id->value, 1));

    $stored = app(WorkoutAnalysisRepository::class)->findForUser($id, $result->snapshot->userId);
    expect($stored?->ai()?->status())->toBe(AnalysisStatus::Pending);
    expect($stored?->context())->toBeNull();
    Queue::assertPushed(GenerateWorkoutAIAnalysisJob::class, fn (GenerateWorkoutAIAnalysisJob $job): bool => $job->attemptNumber === 1 && $job->delay === null);
    app(CalculateWorkoutDeviations::class)->handle(new CalculateWorkoutDeviationsInput($user->id, $id->value, 1));
    Queue::assertPushed(GenerateWorkoutAIAnalysisJob::class, 1);
});

it('recovers a saved pending AI stage without creating stages for old analyses', function () use ($pendingAI): void {
    $analysis = $pendingAI();
    $id = $analysis->id ?? throw new LogicException('Отсутствует анализ.');

    expect(Artisan::call('workout-analysis:recover'))->toBe(0);

    Queue::assertPushed(GenerateWorkoutAIAnalysisJob::class, fn (GenerateWorkoutAIAnalysisJob $job): bool => $job->analysisId === $id->value && $job->attemptNumber === 1);
    $this->assertDatabaseCount('workout_ai_analyses', 1);
});

it('does not call the provider for a different owner', function () use ($pendingAI): void {
    $analysis = $pendingAI();
    $id = $analysis->id ?? throw new LogicException('Отсутствует анализ.');
    $stranger = User::factory()->create();
    $this->mock(AIProvider::class)->shouldNotReceive('analyze');

    expect(fn () => app(GenerateWorkoutAIAnalysis::class)->handle(new GenerateWorkoutAIAnalysisInput($stranger->id, $id->value, 1)))
        ->toThrow(WorkoutAnalysisNotFound::class);
});

it('exposes the saved conclusion and includes it in later history without rebuilding the original context', function () use ($pendingAI): void {
    $user = User::factory()->create();
    $userId = new UserId($user->id);
    $session = WorkoutSessionModel::query()->create([
        'user_id' => $user->id, 'training_program_id' => 11, 'training_program_name' => 'Историческая программа',
        'scheduled_weekday' => 1, 'status' => 'completed', 'started_at' => '2026-09-12 11:00:00', 'completed_at' => '2026-09-12 12:00:00',
    ]);
    $exercise = $session->workoutExercises()->create(['exercise_id' => 10, 'exercise_name' => 'Жим', 'position' => 1, 'status' => 'completed']);
    $exercise->plannedSets()->create(['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 50000]);
    $exercise->workoutSets()->create(['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 50000]);
    $sessionId = new WorkoutSessionId($session->id);
    $data = app(CompletedWorkoutProvider::class)->findForUser($sessionId, $userId) ?? throw new LogicException('Отсутствует тренировка.');
    $snapshot = app(CompletedWorkoutSnapshotFactory::class)->create($data, $userId, $sessionId);
    $old = $pendingAI($user, (new WorkoutDeviationCalculator)->calculate($snapshot));
    $oldId = $old->id ?? throw new LogicException('Отсутствует анализ.');
    $this->mock(AIProvider::class)->shouldReceive('analyze')->once()->andReturnUsing(fn (WorkoutAnalysisId $id, AnalysisContextSnapshot $context): WorkoutAIResult => new WorkoutAIResult($id, $context, 'Все подходы выполнены.', 'Первая тренировка.', 'test-model', 'resp_saved', 1, 1));
    app(GenerateWorkoutAIAnalysis::class)->handle(new GenerateWorkoutAIAnalysisInput($user->id, $oldId->value, 1));
    Sanctum::actingAs($user);

    $this->getJson("/api/workout-sessions/{$session->id}/analysis")->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.ai_analysis.status', 'completed')
        ->assertJsonPath('data.ai_analysis.result.current_workout', 'Все подходы выполнены.')
        ->assertJsonPath('data.ai_analysis.result.history', 'Первая тренировка.')
        ->assertJsonMissingPath('data.ai_analysis.attempts')
        ->assertJsonMissingPath('data.ai_analysis.result.response_id')
        ->assertJsonMissingPath('data.context');
    $next = $pendingAI($user, WorkoutAnalysisFixture::result(sessionId: 99, userId: $user->id));
    $nextId = $next->id ?? throw new LogicException('Отсутствует анализ.');
    $context = app(PrepareWorkoutAnalysisContext::class)->handle(new PrepareWorkoutAnalysisContextInput($user->id, $nextId->value));

    expect($context->sameProgram->all()[0]->conclusion?->currentWorkout)->toBe('Все подходы выполнены.');
    expect($context->sameProgram->all()[0]->conclusion?->analysisId)->toEqual($oldId);
    expect(fn () => DB::transaction(fn () => app(WorkoutAnalysisRepository::class)->save($old)))->toThrow(LogicException::class);
});

it('rejects corrupt persisted AI result versions instead of returning an empty conclusion', function () use ($pendingAI): void {
    $analysis = $pendingAI();
    $id = $analysis->id ?? throw new LogicException('Отсутствует анализ.');
    $userId = $analysis->deviations()->snapshot->userId;
    $this->mock(AIProvider::class)->shouldReceive('analyze')->once()->andReturnUsing(fn (WorkoutAnalysisId $id, AnalysisContextSnapshot $context): WorkoutAIResult => new WorkoutAIResult($id, $context, 'План выполнен.', 'Истории нет.', 'test-model', 'resp_saved', 1, 1));
    app(GenerateWorkoutAIAnalysis::class)->handle(new GenerateWorkoutAIAnalysisInput($userId->value, $id->value, 1));
    WorkoutAIAnalysisModel::query()->where('workout_analysis_id', $id->value)->update(['result_version' => 999]);

    expect(fn () => app(WorkoutAnalysisRepository::class)->findForUser($id, $userId))->toThrow(UnexpectedValueException::class);
});
