<?php

use App\Models\User;
use App\WorkoutAnalysis\Application\Exceptions\AIProviderFailed;
use App\WorkoutAnalysis\Application\Gateways\RecommendationProvider;
use App\WorkoutAnalysis\Application\UseCases\GenerateWorkoutRecommendations\GenerateWorkoutRecommendations;
use App\WorkoutAnalysis\Application\UseCases\GenerateWorkoutRecommendations\GenerateWorkoutRecommendationsInput;
use App\WorkoutAnalysis\Application\UseCases\RecoverWorkoutRecommendations\RecoverWorkoutRecommendations;
use App\WorkoutAnalysis\Application\UseCases\RecoverWorkoutRecommendations\RecoverWorkoutRecommendationsInput;
use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisEvidenceReference;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationBatch;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationProposal;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAIResult;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutRecommendationGenerationModel;
use App\WorkoutAnalysis\Infrastructure\Queue\GenerateWorkoutRecommendationsJob;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

uses(DatabaseMigrations::class);

$pendingRecommendations = static function (bool $empty = false, int $sessionId = 51, bool $successful = true): WorkoutAnalysis {
    $user = User::factory()->create();
    $result = Fixture::result(sessionId: $sessionId, userId: $user->id, exercise: Fixture::exercise(actual: [[$successful ? 10 : 8, 50000]]));
    $now = now()->subMinute()->toDateTimeImmutable();
    $analysis = WorkoutAnalysis::initialize($result->snapshot, $now);
    $analysis->startDeviationAttempt(1, $now, $now->modify('+30 seconds'));
    $analysis->completeDeviationAttempt(1, $result, $now);
    $analysis->scheduleAI($now);
    $context = new AnalysisContextSnapshot($result, new WorkoutHistoryWindow, new WorkoutHistoryWindow, $now);
    $analysis->attachContext($context);
    $analysis->attachRecommendationContext(['program_id' => 11, 'exercises' => $empty ? [] : [[
        'exercise_id' => 10, 'sets' => [['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 50000]],
        'revision' => 0, 'successes' => $successful ? 1 : 0, 'failures' => $successful ? 0 : 1,
        'completed_since_replacement' => 1, 'completed_since_rejection' => 4, 'currently_successful' => $successful,
    ]], 'catalog' => []]);
    $analysis = DB::transaction(fn () => app(WorkoutAnalysisRepository::class)->add($analysis));
    $id = $analysis->id ?? throw new LogicException;
    $analysis->startAIAttempt(1, $now, $now->modify('+30 seconds'));
    $analysis->completeAIAttempt(1, new WorkoutAIResult($id, $context, 'План выполнен.', 'История учтена.', 'test-model', 'resp', 1, 1), $now);
    $analysis->scheduleRecommendations($now);
    DB::transaction(fn () => app(WorkoutAnalysisRepository::class)->save($analysis));

    return $analysis;
};
$recommendationInput = static fn (WorkoutAnalysis $analysis, int $attempt = 1): GenerateWorkoutRecommendationsInput => new GenerateWorkoutRecommendationsInput(
    $analysis->deviations()->snapshot->userId->value, ($analysis->id ?? throw new LogicException)->value, $attempt,
);
$proposal = static fn (WorkoutAnalysis $analysis, int $exerciseId = 10): RecommendationProposal => new RecommendationProposal(
    $exerciseId, 'progression', null, Fixture::sets([[10, 52500]]), 'План выполнен в первой тренировке.',
    new AnalysisEvidenceReference($analysis->id ?? throw new LogicException, $analysis->deviations()->snapshot->workoutSessionId, new ExerciseId(10)),
);

it('persists an admitted batch once and keeps provider calls outside transactions', function () use ($pendingRecommendations, $recommendationInput, $proposal): void {
    Queue::fake();
    $analysis = $pendingRecommendations();
    $batch = new RecommendationBatch([$proposal($analysis)]);
    $this->mock(RecommendationProvider::class)->shouldReceive('generate')->once()->andReturnUsing(function () use ($batch): RecommendationBatch {
        expect(DB::transactionLevel())->toBe(0);

        return $batch;
    });
    $stage = app(GenerateWorkoutRecommendations::class)->handle($recommendationInput($analysis));
    app(GenerateWorkoutRecommendations::class)->handle($recommendationInput($analysis));
    expect($stage?->status())->toBe(AnalysisStatus::Completed);
    $this->assertDatabaseCount('workout_recommendations', 1);
    $this->assertDatabaseCount('workout_recommendation_attempts', 1);
    $stored = app(WorkoutAnalysisRepository::class)->findForUser($analysis->id ?? throw new LogicException, $analysis->deviations()->snapshot->userId);
    expect($stored?->overallStatus())->toBe(AnalysisStatus::Completed);
    expect($stored?->recommendations()?->result)->toEqual($batch);
});

it('publishes a weight adjustment after the first workout falls below the plan', function () use ($pendingRecommendations, $recommendationInput): void {
    Queue::fake();
    $analysis = $pendingRecommendations(successful: false);
    $proposal = new RecommendationProposal(10, 'adjustment', null, Fixture::sets([[10, 47500]]), 'В первой тренировке выполнено 8 повторений из 10.',
        new AnalysisEvidenceReference($analysis->id ?? throw new LogicException, $analysis->deviations()->snapshot->workoutSessionId, new ExerciseId(10)));
    $this->mock(RecommendationProvider::class)->shouldReceive('generate')->once()->andReturn(new RecommendationBatch([$proposal]));

    $stage = app(GenerateWorkoutRecommendations::class)->handle($recommendationInput($analysis));

    expect($stage?->status())->toBe(AnalysisStatus::Completed);
    $this->assertDatabaseHas('workout_recommendations', ['exercise_id' => 10, 'change_type' => 'adjustment']);
    Sanctum::actingAs(User::query()->findOrFail($analysis->deviations()->snapshot->userId->value));
    $this->getJson('/api/workout-sessions/51/analysis')->assertOk()
        ->assertJsonPath('data.overall_status', 'completed')
        ->assertJsonPath('data.recommendation_generation.items.0.proposed_sets.0.working_weight_kg', 47.5);
    Queue::assertNothingPushed();
});

it('completes unavailable programs without contacting the provider', function () use ($pendingRecommendations, $recommendationInput): void {
    Queue::fake();
    $analysis = $pendingRecommendations(true);
    $this->mock(RecommendationProvider::class)->shouldNotReceive('generate');
    $stage = app(GenerateWorkoutRecommendations::class)->handle($recommendationInput($analysis));
    expect($stage?->status())->toBe(AnalysisStatus::Completed);
    expect($stage?->result?->noChangeReason)->not->toBeNull();
    $this->assertDatabaseCount('workout_recommendations', 0);
});

it('completes a valid empty answer with its reason', function () use ($pendingRecommendations, $recommendationInput): void {
    Queue::fake();
    $analysis = $pendingRecommendations();
    $this->mock(RecommendationProvider::class)->shouldReceive('generate')->once()->andReturn(new RecommendationBatch([], 'План подходит.'));
    $stage = app(GenerateWorkoutRecommendations::class)->handle($recommendationInput($analysis));
    expect($stage?->result?->noChangeReason)->toBe('План подходит.');
    Sanctum::actingAs(User::query()->findOrFail($analysis->deviations()->snapshot->userId->value));
    $this->getJson('/api/workout-sessions/51/analysis')->assertOk()
        ->assertJsonPath('data.overall_status', 'completed')
        ->assertJsonPath('data.recommendation_generation.items', [])
        ->assertJsonPath('data.recommendation_generation.no_change_reason', 'План подходит.');
    expect($stage?->status())->toBe(AnalysisStatus::Completed);
    $this->assertDatabaseCount('workout_recommendations', 0);
});

it('preserves rejection reasons for partial success and total rejection', function (bool $partial) use ($pendingRecommendations, $recommendationInput, $proposal): void {
    Queue::fake();
    $analysis = $pendingRecommendations();
    $proposals = $partial ? [$proposal($analysis), $proposal($analysis, 999)] : [$proposal($analysis, 999)];
    $this->mock(RecommendationProvider::class)->shouldReceive('generate')->once()->andReturn(new RecommendationBatch($proposals));
    $stage = app(GenerateWorkoutRecommendations::class)->handle($recommendationInput($analysis));
    expect($stage?->status())->toBe($partial ? AnalysisStatus::Completed : AnalysisStatus::Failed);
    expect($stage?->rejectedReasons)->toBe(['999:source_not_in_program']);
    expect($stage?->currentAttempt()->failureCode)->toBe($partial ? null : AnalysisFailureCode::RecommendationsRejected);
    $this->assertDatabaseCount('workout_recommendations', $partial ? 1 : 0);
    $stored = app(WorkoutAnalysisRepository::class)->findForUser($analysis->id ?? throw new LogicException, $analysis->deviations()->snapshot->userId);
    expect($stored?->recommendations()?->rejectedReasons)->toBe(['999:source_not_in_program']);
    Sanctum::actingAs(User::query()->findOrFail($analysis->deviations()->snapshot->userId->value));
    $response = $this->getJson('/api/workout-sessions/51/analysis')->assertOk()
        ->assertJsonPath('data.overall_status', $partial ? 'completed' : 'failed')
        ->assertJsonPath('data.recommendation_generation.rejected_reasons', ['999:source_not_in_program']);
    if (! $partial) {
        $response->assertJsonPath('data.recommendation_generation.items', null)
            ->assertJsonPath('data.recommendation_generation.failure_code', 'recommendations_rejected');
    }
})->with([true, false]);

it('retries transient failures with the same frozen context and resets the manual retry cycle', function () use ($pendingRecommendations, $recommendationInput): void {
    Queue::fake();
    $this->freezeTime();
    $analysis = $pendingRecommendations();
    $context = $analysis->recommendationContext();
    $this->mock(RecommendationProvider::class)->shouldReceive('generate')->times(3)->andReturnUsing(function (WorkoutAIResult $ai, array $actual) use ($context): never {
        expect($actual)->toBe($context);
        throw new AIProviderFailed(AnalysisFailureCode::ProviderUnavailable);
    });
    foreach ([1 => 0, 2 => 5, 3 => 30] as $attempt => $delay) {
        $this->travel($delay)->seconds();
        app(GenerateWorkoutRecommendations::class)->handle($recommendationInput($analysis, $attempt));
    }
    $stored = app(WorkoutAnalysisRepository::class)->findForUser($analysis->id ?? throw new LogicException, $analysis->deviations()->snapshot->userId);
    expect($stored?->recommendations()?->status())->toBe(AnalysisStatus::Failed);
    Queue::assertPushed(GenerateWorkoutRecommendationsJob::class, 2);
    expect(Artisan::call('workout-analysis:retry-recommendations', ['analysisId' => ($analysis->id ?? throw new LogicException)->value]))->toBe(0);
    $stored = app(WorkoutAnalysisRepository::class)->findForUser($analysis->id ?? throw new LogicException, $analysis->deviations()->snapshot->userId);
    expect($stored?->recommendations()?->currentAttempt()->number)->toBe(4);
    expect($stored?->recommendations()?->currentAttempt()->cycleAttempt)->toBe(1);
    expect($stored?->recommendationContext())->toBe($context);
});

it('ignores late responses and writes no proposals after recovery replaces the attempt', function () use ($pendingRecommendations, $recommendationInput, $proposal): void {
    Queue::fake();
    $this->freezeTime();
    $analysis = $pendingRecommendations();
    $this->mock(RecommendationProvider::class)->shouldReceive('generate')->once()->andReturnUsing(function () use ($analysis, $proposal): RecommendationBatch {
        $this->travel(121)->seconds();
        app(RecoverWorkoutRecommendations::class)->handle(new RecoverWorkoutRecommendationsInput($analysis->deviations()->snapshot->userId->value, 51));

        return new RecommendationBatch([$proposal($analysis)]);
    });
    $stage = app(GenerateWorkoutRecommendations::class)->handle($recommendationInput($analysis));
    expect($stage?->status())->toBe(AnalysisStatus::Pending);
    expect($stage?->currentAttempt()->number)->toBe(2);
    expect($stage?->result)->toBeNull();
    $this->assertDatabaseCount('workout_recommendations', 0);
    Queue::assertPushed(GenerateWorkoutRecommendationsJob::class, 1);
});

it('rejects unsupported saved recommendation versions', function () use ($pendingRecommendations, $recommendationInput): void {
    Queue::fake();
    $analysis = $pendingRecommendations();
    $this->mock(RecommendationProvider::class)->shouldReceive('generate')->once()->andReturn(new RecommendationBatch([], 'План подходит.'));
    app(GenerateWorkoutRecommendations::class)->handle($recommendationInput($analysis));
    WorkoutRecommendationGenerationModel::query()->where('workout_analysis_id', ($analysis->id ?? throw new LogicException)->value)->update(['result_version' => 999]);
    expect(fn () => app(WorkoutAnalysisRepository::class)->findForUser($analysis->id ?? throw new LogicException, $analysis->deviations()->snapshot->userId))->toThrow(UnexpectedValueException::class);
});

it('recovers persisted pending recommendations without backfilling old completed AI stages', function () use ($pendingRecommendations): void {
    Queue::fake();
    $this->freezeTime();
    $pending = $pendingRecommendations();
    $old = $pendingRecommendations(sessionId: 52);
    WorkoutRecommendationGenerationModel::query()->where('workout_analysis_id', ($old->id ?? throw new LogicException)->value)->delete();

    expect(Artisan::call('workout-analysis:recover'))->toBe(0);

    Queue::assertPushed(GenerateWorkoutRecommendationsJob::class, fn (GenerateWorkoutRecommendationsJob $job): bool => $job->analysisId === ($pending->id ?? throw new LogicException)->value && $job->attemptNumber === 1);
    Queue::assertPushed(GenerateWorkoutRecommendationsJob::class, 1);
    $this->assertDatabaseCount('workout_recommendation_generations', 1);
    $stored = app(WorkoutAnalysisRepository::class)->findForUser($old->id ?? throw new LogicException, $old->deviations()->snapshot->userId);
    expect($stored?->recommendations())->toBeNull();
});

it('leaves permanent provider errors terminal without scheduling an automatic retry', function (AnalysisFailureCode $failure) use ($pendingRecommendations, $recommendationInput): void {
    Queue::fake();
    $analysis = $pendingRecommendations();
    $this->mock(RecommendationProvider::class)->shouldReceive('generate')->once()->andThrow(new AIProviderFailed($failure));

    $stage = app(GenerateWorkoutRecommendations::class)->handle($recommendationInput($analysis));

    expect($stage?->status())->toBe(AnalysisStatus::Failed);
    expect($stage?->currentAttempt()->failureCode)->toBe($failure);
    Queue::assertNothingPushed();
    $this->assertDatabaseCount('workout_recommendations', 0);
})->with([AnalysisFailureCode::AIRefused, AnalysisFailureCode::ProviderRejected, AnalysisFailureCode::InvalidAIResponse, AnalysisFailureCode::IncompleteAIResponse]);
