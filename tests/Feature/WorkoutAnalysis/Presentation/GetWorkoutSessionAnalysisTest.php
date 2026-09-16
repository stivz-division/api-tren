<?php

use App\Models\User;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Domain\Collections\ExercisePerformanceCollection;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\ExerciseCompletionStatus;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ProgramName;
use App\WorkoutAnalysis\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture;

uses(LazilyRefreshDatabase::class);

$persistAnalysis = static function (User $user, string $status = 'pending'): WorkoutAnalysis {
    $now = new DateTimeImmutable('2026-09-17T12:00:00+07:00');
    $snapshot = new CompletedWorkoutSnapshot(
        new WorkoutSessionId(51), new UserId($user->id), new TrainingProgramId(11),
        new ProgramName('Грудь и трицепс'), $now,
        new ExercisePerformanceCollection(
            WorkoutAnalysisFixture::exercise([[10, 50_000]], [[12, 52_500], [5, 20_000]]),
            WorkoutAnalysisFixture::exercise([[10, 20_000]], [], 20, 2, ExerciseCompletionStatus::Skipped),
            WorkoutAnalysisFixture::exercise([[10, 0]], [[10, 0]], 30, 3),
        ),
    );
    $analysis = WorkoutAnalysis::initialize($snapshot, $now);
    if ($status !== 'pending') {
        $analysis->startDeviationAttempt(1, $now, $now->modify('+120 seconds'));
    }
    if ($status === 'completed') {
        $analysis->completeDeviationAttempt(1, (new WorkoutDeviationCalculator)->calculate($snapshot), $now);
    }
    if ($status === 'failed') {
        $analysis->failDeviationAttempt(1, AnalysisFailureCode::CalculationFailed, $now, null);
    }

    return app(AnalysisTransaction::class)->execute(
        new UserId($user->id),
        fn (): WorkoutAnalysis => app(WorkoutAnalysisRepository::class)->add($analysis),
    );
};

it('requires authentication to read an analysis', function (): void {
    $this->getJson('/api/workout-sessions/51/analysis')->assertUnauthorized();
});

it('returns the saved stage without exposing attempts or starting jobs', function (string $status, ?string $failureCode) use ($persistAnalysis): void {
    $user = User::factory()->create();
    $analysis = $persistAnalysis($user, $status);
    Sanctum::actingAs($user);
    Queue::fake();

    $this->getJson('/api/workout-sessions/51/analysis')->assertOk()->assertExactJson([
        'data' => [
            'id' => $analysis->id?->value,
            'workout_session_id' => 51,
            'status' => $status,
            'failure_code' => $failureCode,
            'result' => null,
        ],
    ]);

    Queue::assertNothingPushed();
    $this->assertDatabaseCount('workout_deviation_attempts', 1);
})->with([
    'pending' => ['pending', null],
    'processing' => ['processing', null],
    'failed' => ['failed', 'calculation_failed'],
]);

it('returns completed results with kilogram units and UTC dates', function () use ($persistAnalysis): void {
    $user = User::factory()->create();
    $persistAnalysis($user, 'completed');
    Sanctum::actingAs($user);

    $this->getJson('/api/workout-sessions/51/analysis')->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.failure_code', null)
        ->assertJsonPath('data.result.training_program_id', 11)
        ->assertJsonPath('data.result.program_name', 'Грудь и трицепс')
        ->assertJsonPath('data.result.workout_completed_at', '2026-09-17T05:00:00+00:00')
        ->assertJsonPath('data.result.completed_exercises', 2)
        ->assertJsonPath('data.result.skipped_exercises', 1)
        ->assertJsonPath('data.result.sets', ['planned' => 3, 'actual' => 3, 'difference' => 0, 'percentage' => 0])
        ->assertJsonPath('data.result.repetitions', ['planned' => 30, 'actual' => 27, 'difference' => -3, 'percentage' => -10])
        ->assertJsonPath('data.result.volume_kg.planned', 700)
        ->assertJsonPath('data.result.volume_kg.actual', 730)
        ->assertJsonPath('data.result.volume_kg.difference', 30)
        ->assertJsonPath('data.result.exercises.0.plan_fulfilled', true)
        ->assertJsonPath('data.result.exercises.0.planned_sets.0.working_weight_kg', 50)
        ->assertJsonPath('data.result.exercises.0.actual_sets.0.working_weight_kg', 52.5)
        ->assertJsonPath('data.result.exercises.0.set_comparisons.0.working_weight_kg', ['planned' => 50, 'actual' => 52.5, 'difference' => 2.5, 'percentage' => 5])
        ->assertJsonPath('data.result.exercises.0.set_comparisons.0.repetitions.difference', 2)
        ->assertJsonPath('data.result.exercises.0.set_comparisons.0.volume_kg', ['planned' => 500, 'actual' => 630, 'difference' => 130, 'percentage' => 26])
        ->assertJsonPath('data.result.exercises.0.set_comparisons.1.planned', null)
        ->assertJsonPath('data.result.exercises.0.set_comparisons.1.repetitions', null)
        ->assertJsonPath('data.result.exercises.0.set_comparisons.1.working_weight_kg', null)
        ->assertJsonPath('data.result.exercises.0.set_comparisons.1.volume_kg', null)
        ->assertJsonPath('data.result.exercises.1.status', 'skipped')
        ->assertJsonPath('data.result.exercises.1.plan_fulfilled', false)
        ->assertJsonPath('data.result.exercises.1.actual_sets', [])
        ->assertJsonPath('data.result.exercises.1.set_comparisons.0.actual', null)
        ->assertJsonPath('data.result.exercises.1.volume_kg.difference', -200)
        ->assertJsonPath('data.result.exercises.2.volume_kg.percentage', null)
        ->assertJsonMissingPath('data.attempts')
        ->assertJsonMissingPath('data.user_id');
});

it('hides foreign analyses even when the owner id is supplied', function () use ($persistAnalysis): void {
    $owner = User::factory()->create();
    $persistAnalysis($owner);
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/workout-sessions/51/analysis?user_id='.$owner->id)
        ->assertNotFound()->assertExactJson([
            'code' => 'workout_analysis_not_found',
            'message' => 'Анализ тренировки не найден.',
        ]);
});

it('does not initialize a missing analysis on read', function (): void {
    Sanctum::actingAs(User::factory()->create());
    Queue::fake();

    $this->getJson('/api/workout-sessions/51/analysis')->assertNotFound()->assertExactJson([
        'code' => 'workout_analysis_not_found',
        'message' => 'Анализ тренировки не найден.',
    ]);

    $this->assertDatabaseCount('workout_analyses', 0);
    Queue::assertNothingPushed();
});

it('uses the route session id rather than a supplied query value', function () use ($persistAnalysis): void {
    $user = User::factory()->create();
    $persistAnalysis($user);
    Sanctum::actingAs($user);

    $this->getJson('/api/workout-sessions/52/analysis?workout_session_id=51')
        ->assertNotFound()->assertJsonPath('code', 'workout_analysis_not_found');
});

it('rejects an invalid numeric session identifier', function (string $id, string $message): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/workout-sessions/'.$id.'/analysis')
        ->assertUnprocessable()->assertJsonValidationErrors('workout_session_id')
        ->assertJsonPath('errors.workout_session_id.0', $message);
})->with([
    'zero' => ['0', 'Идентификатор тренировочной сессии должен быть положительным числом.'],
    'overflow' => ['99999999999999999999999999', 'Идентификатор тренировочной сессии должен быть целым числом.'],
]);
