<?php

use App\Models\User;
use App\WorkoutAnalysis\Application\DTO\DeviationTask;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTaskScheduler;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\Gateways\CompletedWorkoutProvider;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutAnalysisModel;
use App\WorkoutAnalysis\Infrastructure\Queue\CalculateWorkoutDeviationsJob;
use App\WorkoutExecution\Application\Gateways\WorkoutClock;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSessionModel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\WorkoutExecution\FrozenWorkoutClock;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('workout-execution.mutation_lock.store', 'array');
    $this->travelTo(new DateTimeImmutable('2026-09-17T12:00:00+00:00'));
    $this->app->instance(WorkoutClock::class, new FrozenWorkoutClock(now()->toDateTimeImmutable()));
});

$resolvedSession = static function (): WorkoutSessionModel {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $session = WorkoutSessionModel::query()->create([
        'user_id' => $user->id,
        'training_program_id' => 19,
        'training_program_name' => 'Историческая программа',
        'scheduled_weekday' => 1,
        'status' => 'in_progress',
        'started_at' => now()->subHour(),
    ]);
    $exercise = $session->workoutExercises()->create([
        'exercise_id' => 31, 'exercise_name' => 'Жим лежа', 'position' => 1, 'status' => 'completed',
    ]);
    $exercise->plannedSets()->create(['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 50_000]);
    $exercise->workoutSets()->create(['position' => 1, 'repetitions' => 12, 'working_weight_grams' => 55_000]);

    return $session;
};

it('atomically creates pending analysis when the workout is completed and dispatches its first attempt', function () use ($resolvedSession): void {
    Queue::fake();
    $session = $resolvedSession();

    $this->postJson("/api/workout-sessions/{$session->id}/complete")
        ->assertOk()->assertJsonPath('data.status', 'completed');

    $this->assertDatabaseHas('workout_analyses', ['workout_session_id' => $session->id, 'user_id' => $session->user_id]);
    $this->assertDatabaseHas('workout_deviation_analyses', ['status' => 'pending', 'current_attempt_number' => 1]);
    Queue::assertPushed(CalculateWorkoutDeviationsJob::class, fn (CalculateWorkoutDeviationsJob $job): bool => $job->userId === $session->user_id && $job->attemptNumber === 1);
});

it('rolls back completion when a completed snapshot cannot be obtained', function () use ($resolvedSession): void {
    Queue::fake();
    $session = $resolvedSession();
    $this->mock(CompletedWorkoutProvider::class)
        ->shouldReceive('findForUser')->once()->andReturnNull();

    $this->postJson("/api/workout-sessions/{$session->id}/complete")->assertInternalServerError();

    expect($session->refresh()->status)->toBe('in_progress');
    expect($session->completed_at)->toBeNull();
    $this->assertDatabaseCount('workout_analyses', 0);
    Queue::assertNothingPushed();
});

it('waits for the outer commit before dispatching', function () use ($resolvedSession): void {
    Queue::fake();
    $session = $resolvedSession();
    DB::beginTransaction();

    $this->postJson("/api/workout-sessions/{$session->id}/complete")->assertOk();

    Queue::assertNothingPushed();
    DB::commit();
    Queue::assertPushed(CalculateWorkoutDeviationsJob::class, 1);
});

it('discards the analysis and dispatch when the outer transaction rolls back', function () use ($resolvedSession): void {
    Queue::fake();
    $session = $resolvedSession();
    DB::beginTransaction();
    $this->postJson("/api/workout-sessions/{$session->id}/complete")->assertOk();

    DB::rollBack();

    expect($session->refresh()->status)->toBe('in_progress');
    $this->assertDatabaseCount('workout_analyses', 0);
    Queue::assertNothingPushed();
});

it('does not recreate or dispatch analysis on repeated completion', function () use ($resolvedSession): void {
    Queue::fake();
    $session = $resolvedSession();
    $this->postJson("/api/workout-sessions/{$session->id}/complete")->assertOk();

    $this->postJson("/api/workout-sessions/{$session->id}/complete")->assertOk();

    $this->assertDatabaseCount('workout_analyses', 1);
    $this->assertDatabaseCount('workout_deviation_attempts', 1);
    Queue::assertPushed(CalculateWorkoutDeviationsJob::class, 1);
});

it('leaves historical completed workouts without analysis on repeated completion and recovery', function () use ($resolvedSession): void {
    Queue::fake();
    $session = $resolvedSession();
    $session->update(['status' => 'completed', 'completed_at' => now()->subMinutes(30)]);

    $this->postJson("/api/workout-sessions/{$session->id}/complete")->assertOk();
    expect(Artisan::call('workout-analysis:recover'))->toBe(0);

    $this->assertDatabaseCount('workout_analyses', 0);
    Queue::assertNothingPushed();
});

it('recovers a pending attempt after dispatch fails without undoing workout completion', function () use ($resolvedSession): void {
    $session = $resolvedSession();
    config()->set('workout-analysis.queue.connection', 'unavailable-analysis-queue');

    $this->postJson("/api/workout-sessions/{$session->id}/complete")->assertOk();

    expect($session->refresh()->status)->toBe('completed');
    $this->assertDatabaseHas('workout_deviation_analyses', ['status' => 'pending', 'current_attempt_number' => 1]);
    Queue::fake();
    $this->travel(61)->seconds();
    expect(Artisan::call('workout-analysis:recover'))->toBe(0);
    Queue::assertPushed(CalculateWorkoutDeviationsJob::class, fn (CalculateWorkoutDeviationsJob $job): bool => $job->attemptNumber === 1);
    $this->assertDatabaseCount('workout_deviation_attempts', 1);
});

it('processes the persisted snapshot and ignores duplicate job delivery', function () use ($resolvedSession): void {
    Queue::fake();
    $session = $resolvedSession();
    $this->postJson("/api/workout-sessions/{$session->id}/complete")->assertOk();
    $analysisId = WorkoutAnalysisModel::query()->sole()->id;
    $job = new CalculateWorkoutDeviationsJob($analysisId, $session->user_id, 1);

    $this->app->call([$job, 'handle']);
    $this->app->call([$job, 'handle']);

    $analysis = app(WorkoutAnalysisRepository::class)->findForUser(
        new WorkoutAnalysisId($analysisId),
        new UserId($session->user_id),
    );
    $analysis ?? throw new LogicException('Отсутствует тестовый анализ.');
    expect($analysis->deviations()->status()->value)->toBe('completed');
    $result = $analysis->deviations()->result ?? throw new LogicException('Отсутствует результат анализа.');
    expect($result->volume->actual)->toBe(660_000);
    expect($result->volume->planned)->toBe(500_000);
    $this->assertDatabaseCount('workout_deviation_attempts', 1);
    Queue::assertPushed(CalculateWorkoutDeviationsJob::class, 1);
});

it('does not enqueue fresh pending work during recovery', function () use ($resolvedSession): void {
    Queue::fake();
    $session = $resolvedSession();
    $this->postJson("/api/workout-sessions/{$session->id}/complete")->assertOk();

    expect(Artisan::call('workout-analysis:recover'))->toBe(0);

    Queue::assertPushed(CalculateWorkoutDeviationsJob::class, 1);
});

it('recovers expired processing attempts up to the budget and permits a service retry', function () use ($resolvedSession): void {
    Queue::fake();
    $session = $resolvedSession();
    $this->postJson("/api/workout-sessions/{$session->id}/complete")->assertOk();
    $model = WorkoutAnalysisModel::query()->sole();
    $userId = new UserId($session->user_id);
    $analysisId = new WorkoutAnalysisId($model->id);
    $repository = app(WorkoutAnalysisRepository::class);
    $transaction = app(AnalysisTransaction::class);

    for ($number = 1; $number <= 3; $number++) {
        $transaction->execute($userId, function () use ($repository, $analysisId, $userId, $number): void {
            $analysis = $repository->findForUser($analysisId, $userId) ?? throw new LogicException('Отсутствует анализ.');
            $analysis->startDeviationAttempt($number, now()->toDateTimeImmutable(), now()->addSeconds(120)->toDateTimeImmutable());
            $repository->save($analysis);
        });
        $this->travel(120)->seconds();
        expect(Artisan::call('workout-analysis:recover'))->toBe(0);
        $this->travel(30)->seconds();
    }

    $this->assertDatabaseHas('workout_deviation_analyses', ['status' => 'failed', 'current_attempt_number' => 3]);
    $this->assertDatabaseCount('workout_deviation_attempts', 3);
    expect(Artisan::call('workout-analysis:recover'))->toBe(0);
    Queue::assertPushed(CalculateWorkoutDeviationsJob::class, 3);

    expect(Artisan::call('workout-analysis:retry', ['analysisId' => $model->id]))->toBe(0);

    $this->assertDatabaseHas('workout_deviation_attempts', ['number' => 4, 'cycle_attempt' => 1, 'status' => 'pending']);
    Queue::assertPushed(CalculateWorkoutDeviationsJob::class, fn (CalculateWorkoutDeviationsJob $job): bool => $job->attemptNumber === 4);
    $this->app->call([new CalculateWorkoutDeviationsJob($model->id, $session->user_id, 1), 'handle']);
    $this->assertDatabaseHas('workout_deviation_analyses', ['status' => 'pending', 'current_attempt_number' => 4]);
});

it('keeps processing other recovery candidates when one stored snapshot is damaged', function () use ($resolvedSession): void {
    Queue::fake();
    $first = $resolvedSession();
    $this->postJson("/api/workout-sessions/{$first->id}/complete")->assertOk();
    $second = $resolvedSession();
    $this->postJson("/api/workout-sessions/{$second->id}/complete")->assertOk();
    WorkoutAnalysisModel::query()
        ->where('workout_session_id', $first->id)->update(['snapshot_version' => 99]);
    $this->travel(61)->seconds();
    config()->set('workout-analysis.recovery.batch_size', 1);

    expect(Artisan::call('workout-analysis:recover'))->toBe(1);

    Queue::assertPushed(CalculateWorkoutDeviationsJob::class, 3);
    Queue::assertPushed(CalculateWorkoutDeviationsJob::class, fn (CalculateWorkoutDeviationsJob $job): bool => $job->userId === $second->user_id);
});

it('never creates analysis on exercise completion alone', function () use ($resolvedSession): void {
    Queue::fake();
    $session = $resolvedSession();
    $session->workoutExercises()->update(['status' => 'pending']);

    $this->postJson("/api/workout-sessions/{$session->id}/exercises/31/complete", [
        'sets' => [['repetitions' => 12, 'working_weight_kg' => 55]],
    ])->assertOk();

    expect($session->refresh()->status)->toBe('in_progress');
    $this->assertDatabaseCount('workout_analyses', 0);
    Queue::assertNothingPushed();
});

it('reads a source snapshot only for its owner and keeps historical set ordering', function () use ($resolvedSession): void {
    $session = $resolvedSession();
    $provider = app(CompletedWorkoutProvider::class);
    $id = new WorkoutSessionId($session->id);
    $other = User::factory()->create();

    expect($provider->findForUser($id, new UserId($other->id)))->toBeNull();
    $snapshot = $provider->findForUser($id, new UserId($session->user_id))
        ?? throw new LogicException('Отсутствует снимок тренировки.');

    expect($snapshot->programName)->toBe('Историческая программа');
    expect($snapshot->exercises[0]->plannedSets[0]->workingWeightInGrams)->toBe(50_000);
    expect($snapshot->exercises[0]->actualSets[0]->repetitions)->toBe(12);
});

it('rejects invalid or missing analysis identifiers in the service retry command', function (): void {
    expect(Artisan::call('workout-analysis:retry', ['analysisId' => '-1']))->toBe(2);
    expect(Artisan::call('workout-analysis:retry', ['analysisId' => '999']))->toBe(1);
});

it('rounds delayed delivery up so redis cannot acknowledge an attempt before its scheduled microsecond', function (): void {
    Queue::fake();

    app(AnalysisTaskScheduler::class)->schedule(
        new DeviationTask(1, 7, 2, new DateTimeImmutable('2026-09-17T12:00:05.900000+00:00')),
    );

    Queue::assertPushed(CalculateWorkoutDeviationsJob::class, fn (CalculateWorkoutDeviationsJob $job): bool => $job->delay instanceof DateTimeInterface && $job->delay->format('H:i:s.u') === '12:00:06.000000');
});

it('rejects inconsistent persisted stage and attempt states in postgres', function () use ($resolvedSession): void {
    Queue::fake();
    $session = $resolvedSession();
    $this->postJson("/api/workout-sessions/{$session->id}/complete")->assertOk();

    expect(fn () => DB::transaction(fn () => DB::table('workout_deviation_attempts')->update(['status' => 'processing'])))
        ->toThrow(QueryException::class, 'workout_deviation_attempt_state_check');
    expect(fn () => DB::transaction(fn () => DB::table('workout_deviation_analyses')->update(['status' => 'completed'])))
        ->toThrow(QueryException::class, 'workout_deviation_result_check');

    $this->assertDatabaseHas('workout_deviation_analyses', ['status' => 'pending']);
    Queue::assertPushed(CalculateWorkoutDeviationsJob::class, 1);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'Requires PostgreSQL constraints.');

it('dispatches the initial attempt immediately even when its timestamp has microseconds', function (): void {
    Queue::fake();
    $now = new DateTimeImmutable('2026-09-17T12:00:00.900000+00:00');
    $this->travelTo($now);

    app(AnalysisTaskScheduler::class)->schedule(new DeviationTask(1, 7, 1, $now));

    Queue::assertPushed(CalculateWorkoutDeviationsJob::class, fn (CalculateWorkoutDeviationsJob $job): bool => $job->delay === null);
});
