<?php

use App\Models\User;
use App\WorkoutAnalysis\Application\DTO\WorkoutHistoryQuery;
use App\WorkoutAnalysis\Application\Factories\CompletedWorkoutSnapshotFactory;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\Gateways\CompletedWorkoutProvider;
use App\WorkoutAnalysis\Application\Gateways\WorkoutHistoryProvider;
use App\WorkoutAnalysis\Application\UseCases\PrepareWorkoutAnalysisContext\PrepareWorkoutAnalysisContext;
use App\WorkoutAnalysis\Application\UseCases\PrepareWorkoutAnalysisContext\PrepareWorkoutAnalysisContextInput;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutAnalysisModel;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutDeviationAnalysisModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSessionModel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

$session = static function (User $user, int $programId, string $completedAt, string $status = 'completed'): WorkoutSessionModel {
    $model = WorkoutSessionModel::query()->create([
        'user_id' => $user->id, 'training_program_id' => $programId, 'training_program_name' => 'Исторический план',
        'scheduled_weekday' => 1, 'status' => $status,
        'started_at' => '2026-09-01 12:00:00', 'completed_at' => $status === 'completed' ? $completedAt : null,
    ]);
    $exercise = $model->workoutExercises()->create(['exercise_id' => 31, 'exercise_name' => 'Историческое упражнение', 'position' => 1, 'status' => 'completed']);
    $exercise->plannedSets()->create(['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 50000]);
    $exercise->workoutSets()->create(['position' => 1, 'repetitions' => 8, 'working_weight_grams' => 45000]);

    return $model;
};

it('selects the latest completed history with independent limits and deterministic ties', function () use ($session): void {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $session($user, 11, '2026-09-10 12:00:00');
    $same = $session($user, 11, '2026-09-12 12:00:00');
    $sameTie = $session($user, 11, '2026-09-12 12:00:00');
    $session($user, 12, '2026-09-10 12:00:00');
    $other = $session($user, 13, '2026-09-13 12:00:00');
    $session($user, 11, '2026-09-14 12:00:00');
    $current = $session($user, 11, '2026-09-14 12:00:00');
    $session($user, 12, '2026-09-15 12:00:00');
    $session($stranger, 11, '2026-09-13 12:00:00');
    $session($user, 11, '2026-09-13 12:00:00', 'in_progress');
    $query = new WorkoutHistoryQuery($user->id, $current->id, 11, new DateTimeImmutable('2026-09-14T12:00:00Z'), 2, 1);

    $history = app(AnalysisTransaction::class)->execute(new UserId($user->id), fn () => app(WorkoutHistoryProvider::class)->read($query));

    expect(array_map(fn ($entry) => $entry->workout->workoutSessionId, $history->sameProgram))->toBe([$sameTie->id, $same->id]);
    expect(array_map(fn ($entry) => $entry->workout->workoutSessionId, $history->otherPrograms))->toBe([$other->id]);
    expect($history->sameProgram[0]->deviations)->toBeNull();
    expect($history->sameProgram[0]->conclusion)->toBeNull();
    expect($history->sameProgram[0]->recommendations)->toBeNull();
    expect($history->sameProgram[0]->workout->programName)->toBe('Исторический план');
    $this->assertDatabaseCount('workout_analyses', 0);
});

$analyze = static function (WorkoutSessionModel $session, bool $completed = true): WorkoutAnalysis {
    $userId = new UserId($session->user_id);
    $sessionId = new WorkoutSessionId($session->id);
    $data = app(CompletedWorkoutProvider::class)->findForUser($sessionId, $userId) ?? throw new LogicException('Отсутствует тренировка.');
    $snapshot = app(CompletedWorkoutSnapshotFactory::class)->create($data, $userId, $sessionId);
    $now = new DateTimeImmutable('2026-09-20T12:00:00Z');
    $analysis = WorkoutAnalysis::initialize($snapshot, $now);
    if ($completed) {
        $analysis->startDeviationAttempt(1, $now, $now->modify('+1 minute'));
        $analysis->completeDeviationAttempt(1, (new WorkoutDeviationCalculator)->calculate($snapshot), $now->modify('+1 second'));
    }

    return DB::transaction(fn () => app(WorkoutAnalysisRepository::class)->add($analysis));
};

it('captures available deviations and calculates missing ones without creating historical jobs', function (string $state) use ($session, $analyze): void {
    Queue::fake();
    $this->travelTo(new DateTimeImmutable('2026-09-21T12:00:00Z'));
    $user = User::factory()->create();
    $past = $session($user, 11, '2026-09-12 12:00:00');
    if ($state !== 'missing') {
        $analyze($past, $state === 'completed');
    }
    $current = $session($user, 11, '2026-09-14 12:00:00');
    $analysis = $analyze($current);
    $analysisId = $analysis->id ?? throw new LogicException('Отсутствует анализ.');
    $query = new WorkoutHistoryQuery($user->id, $current->id, 11, new DateTimeImmutable('2026-09-14T12:00:00Z'), 20, 20);
    $history = app(AnalysisTransaction::class)->execute(new UserId($user->id), fn () => app(WorkoutHistoryProvider::class)->read($query));
    expect($history->sameProgram[0]->deviations !== null)->toBe($state === 'completed');
    $input = new PrepareWorkoutAnalysisContextInput($user->id, $analysisId->value);

    $context = app(PrepareWorkoutAnalysisContext::class)->handle($input);

    $entry = $context->sameProgram->all()[0];
    expect($entry->deviations->volume->planned)->toBe(500000);
    expect($entry->deviations->volume->actual)->toBe(360000);
    expect($entry->deviations->snapshot->programName->value)->toBe('Исторический план');
    expect($entry->conclusion)->toBeNull();
    expect($entry->recommendations)->toBeNull();
    $this->assertDatabaseCount('workout_analyses', $state === 'missing' ? 1 : 2);
    $this->assertDatabaseCount('workout_deviation_attempts', $state === 'missing' ? 1 : 2);
    $past->delete();
    $session($user, 11, '2026-09-13 12:00:00');
    expect(app(PrepareWorkoutAnalysisContext::class)->handle($input))->toEqual($context);
    Queue::assertNothingPushed();
})->with(['missing', 'pending', 'completed']);

it('propagates corrupt cached history and leaves the current context absent', function () use ($session, $analyze): void {
    $this->travelTo(new DateTimeImmutable('2026-09-21T12:00:00Z'));
    $user = User::factory()->create();
    $past = $analyze($session($user, 11, '2026-09-12 12:00:00'));
    $pastId = $past->id ?? throw new LogicException('Отсутствует анализ.');
    WorkoutDeviationAnalysisModel::query()->where('workout_analysis_id', $pastId->value)->update(['result_version' => 999]);
    $current = $analyze($session($user, 11, '2026-09-14 12:00:00'));
    $currentId = $current->id ?? throw new LogicException('Отсутствует анализ.');

    expect(fn () => app(PrepareWorkoutAnalysisContext::class)->handle(new PrepareWorkoutAnalysisContextInput($user->id, $currentId->value)))
        ->toThrow(UnexpectedValueException::class);

    expect(WorkoutAnalysisModel::query()->findOrFail($currentId->value)->context)->toBeNull();
});

it('excludes the current session even when its stored completion time differs from the boundary', function () use ($session): void {
    $user = User::factory()->create();
    $current = $session($user, 11, '2026-09-12 12:00:00');
    $query = new WorkoutHistoryQuery($user->id, $current->id, 11, new DateTimeImmutable('2026-09-14T12:00:00Z'), 20, 20);

    $history = app(AnalysisTransaction::class)->execute(new UserId($user->id), fn () => app(WorkoutHistoryProvider::class)->read($query));

    expect($history->sameProgram)->toBe([]);
});
