<?php

use App\Models\Exercise;
use App\Models\User;
use App\WorkoutAnalysis\Domain\Collections\ExercisePerformanceCollection;
use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ProgramName as AnalysisProgramName;
use App\WorkoutAnalysis\Domain\ValueObjects\TrainingProgramId as AnalysisProgramId;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId as AnalysisUserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId as AnalysisSessionId;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\CompletedWorkoutSnapshotCodec;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutAnalysisModel;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutRecommendationModel;
use App\WorkoutExecution\Application\DTO\WorkoutSessionDTO;
use App\WorkoutExecution\Application\DTO\WorkoutSetInput;
use App\WorkoutExecution\Application\Exceptions\WorkoutReplacementPlanConflict;
use App\WorkoutExecution\Application\Exceptions\WorkoutSessionNotFound;
use App\WorkoutExecution\Application\Gateways\WorkoutClock;
use App\WorkoutExecution\Application\UseCases\ReplaceExercise\ReplaceExercise;
use App\WorkoutExecution\Application\UseCases\ReplaceExercise\ReplaceExerciseInput;
use App\WorkoutExecution\Application\UseCases\StartWorkoutSession\StartWorkoutSession;
use App\WorkoutExecution\Application\UseCases\StartWorkoutSession\StartWorkoutSessionInput;
use App\WorkoutExecution\Domain\Repositories\WorkoutSessionRepository;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutExerciseModel;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\TrainingProgramModel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture;
use Tests\Support\WorkoutExecution\FrozenWorkoutClock;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config(['workout-execution.mutation_lock.store' => 'array']);
    $this->travelTo(new DateTimeImmutable('2026-09-21T12:00:00Z'));
    app()->instance(
        WorkoutClock::class,
        new FrozenWorkoutClock(new DateTimeImmutable('2026-09-21T12:00:00Z')),
    );
});

$fixture = static function (): array {
    $user = User::factory()->create();
    $exercises = array_values(Exercise::factory()->count(5)->create()->all());
    $program = TrainingProgramModel::query()->create(['user_id' => $user->id, 'weekday' => 1, 'name' => 'Программа']);
    foreach (array_slice($exercises, 0, 2) as $index => $exercise) {
        $program->plannedExercises()->create(['exercise_id' => $exercise->id, 'position' => $index + 1])
            ->plannedSets()->create(['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 50000]);
    }
    $session = app(StartWorkoutSession::class)->handle(new StartWorkoutSessionInput($user->id, $program->id));

    return compact('user', 'exercises', 'program', 'session');
};

/** @param array{user:User,exercises:list<Exercise>,program:TrainingProgramModel,session:WorkoutSessionDTO} $f */
function replacePlannedWorkoutExercise(array $f, int $candidate, bool $permanent, int $request = 1): void
{
    app(ReplaceExercise::class)->handle(new ReplaceExerciseInput(
        $f['user']->id,
        $f['session']->id,
        $f['exercises'][0]->id,
        sprintf('00000000-0000-4000-8000-%012d', $request),
        $f['exercises'][$candidate]->id,
        [new WorkoutSetInput(8, 60000)],
        $permanent,
    ));
}

$replace = replacePlannedWorkoutExercise(...);

it('tracks the permanent baseline through mixed replacement chains', function (bool $firstFlag, bool $secondFlag, ?bool $thirdFlag) use ($fixture, $replace): void {
    $f = $fixture();
    $flags = $thirdFlag === null ? [$firstFlag, $secondFlag] : [$firstFlag, $secondFlag, $thirdFlag];
    foreach ($flags as $index => $flag) {
        $replace($f, $index + 2, $flag, $index + 1);
    }
    $target = $f['program']->plannedExercises()->orderBy('position')->with('plannedSets')->firstOrFail();
    expect($target->exercise_id)->toBe($f['exercises'][count($flags) + 1]->id);
    expect($target->plannedSets->firstOrFail()->working_weight_grams)->toBe(60000);
    expect($f['program']->plannedExercises()->where('position', 2)->firstOrFail()->exercise_id)->toBe($f['exercises'][1]->id);
    $stored = app(WorkoutSessionRepository::class)->findForUser(new WorkoutSessionId($f['session']->id), new UserId($f['user']->id)) ?? throw new LogicException('Missing session.');
    expect($stored->workoutExercises()[0]->planBaselineRevision())->toBe(1);
    expect(DB::table('workout_plan_change_barriers')->where('exercise_id', $f['exercises'][1]->id)->exists())->toBeFalse();
})->with([[false, true, null], [true, false, true]]);

it('allows temporary replacements after deletion but rolls back permanent replacement', function () use ($fixture, $replace): void {
    $f = $fixture();
    $f['program']->delete();
    $replace($f, 2, false);
    expect(fn () => $replace($f, 3, true, 2))->toThrow(WorkoutReplacementPlanConflict::class);
    $this->assertDatabaseCount('workout_exercise_replacements', 1);
    $this->assertDatabaseCount('workout_plan_change_barriers', 0);
});

it('rejects a changed load without modifying the session', function () use ($fixture, $replace): void {
    $f = $fixture();
    $f['program']->plannedExercises()->firstOrFail()->plannedSets()->update(['repetitions' => 11]);
    expect(fn () => $replace($f, 2, true))->toThrow(WorkoutReplacementPlanConflict::class);
    $this->assertDatabaseCount('workout_exercise_replacements', 0);
    $this->assertDatabaseCount('workout_plan_change_barriers', 0);
});

it('rejects a returned baseline whose revision changed', function () use ($fixture, $replace): void {
    $f = $fixture();
    $target = $f['program']->plannedExercises()->firstOrFail();
    $target->update(['exercise_id' => $f['exercises'][2]->id]);
    $target->update(['exercise_id' => $f['exercises'][0]->id]);
    DB::table('workout_plan_change_barriers')->insert([
        'user_id' => $f['user']->id,
        'training_program_id' => $f['program']->id,
        'exercise_id' => $f['exercises'][0]->id,
        'revision' => 2,
        'changed_at' => now(),
    ]);
    expect(fn () => $replace($f, 3, true))->toThrow(WorkoutReplacementPlanConflict::class);
    $this->assertDatabaseCount('workout_exercise_replacements', 0);
});

it('guards legacy sessions by load and the time of the barrier', function (bool $changedAfterStart) use ($fixture, $replace): void {
    $f = $fixture();
    WorkoutExerciseModel::query()->where('workout_session_id', $f['session']->id)->update(['source_plan_revision' => null]);
    DB::table('workout_plan_change_barriers')->insert([
        'user_id' => $f['user']->id,
        'training_program_id' => $f['program']->id,
        'exercise_id' => $f['exercises'][0]->id,
        'revision' => 2,
        'changed_at' => now()->addSeconds($changedAfterStart ? 1 : -1),
    ]);
    if ($changedAfterStart) {
        expect(fn () => $replace($f, 2, true))->toThrow(WorkoutReplacementPlanConflict::class);
        $this->assertDatabaseCount('workout_exercise_replacements', 0);
    } else {
        $replace($f, 2, true);
        $this->assertDatabaseCount('workout_exercise_replacements', 1);
    }
})->with([false, true]);

it('does not access another users session or program', function () use ($fixture, $replace): void {
    $f = $fixture();
    $otherUser = User::factory()->create();
    $foreign = $f;
    $foreign['user'] = $otherUser;
    expect(fn () => $replace($foreign, 2, true))->toThrow(WorkoutSessionNotFound::class);
    $f['program']->update(['user_id' => $otherUser->id]);
    expect(fn () => $replace($f, 2, true))->toThrow(WorkoutReplacementPlanConflict::class);
    $this->assertDatabaseCount('workout_exercise_replacements', 0);
});

it('rejects candidates already in the program even after temporary replacement hides them in the session', function () use ($fixture, $replace): void {
    $f = $fixture();
    app(ReplaceExercise::class)->handle(new ReplaceExerciseInput(
        $f['user']->id,
        $f['session']->id,
        $f['exercises'][1]->id,
        '00000000-0000-4000-8000-000000000009',
        $f['exercises'][3]->id,
        [new WorkoutSetInput(8, 60000)],
    ));
    expect(fn () => $replace($f, 1, true))->toThrow(WorkoutReplacementPlanConflict::class);
    $this->assertDatabaseCount('workout_exercise_replacements', 1);
});

it('rolls back the plan and barriers when session saving fails', function () use ($fixture, $replace): void {
    $f = $fixture();
    $repository = app(WorkoutSessionRepository::class);
    app()->instance(WorkoutSessionRepository::class, Mockery::mock(WorkoutSessionRepository::class)
        ->shouldReceive('findForUser')->andReturnUsing(fn (WorkoutSessionId $id, UserId $user) => $repository->findForUser($id, $user))->getMock()
        ->shouldReceive('save')->andThrow(new RuntimeException('save failed'))->getMock());
    expect(fn () => $replace($f, 2, true))->toThrow(RuntimeException::class, 'save failed');
    expect($f['program']->plannedExercises()->firstOrFail()->exercise_id)->toBe($f['exercises'][0]->id);
    $this->assertDatabaseCount('workout_exercise_replacements', 0);
    $this->assertDatabaseCount('workout_plan_change_barriers', 0);
});

it('expires recommendations for old and new targets while preserving other exercises', function () use ($fixture, $replace): void {
    $f = $fixture();
    $snapshot = new CompletedWorkoutSnapshot(
        new AnalysisSessionId($f['session']->id),
        new AnalysisUserId($f['user']->id),
        new AnalysisProgramId($f['program']->id),
        new AnalysisProgramName($f['program']->name),
        now()->subDay()->toDateTimeImmutable(),
        new ExercisePerformanceCollection(
            WorkoutAnalysisFixture::exercise(id: $f['exercises'][0]->id),
            WorkoutAnalysisFixture::exercise(id: $f['exercises'][1]->id, position: 2),
        ),
    );
    $analysis = WorkoutAnalysisModel::query()->create([
        'user_id' => $f['user']->id,
        'workout_session_id' => $f['session']->id,
        'snapshot' => app(CompletedWorkoutSnapshotCodec::class)->encode($snapshot),
        'snapshot_version' => CompletedWorkoutSnapshotCodec::VERSION,
    ]);
    $items = [];
    foreach (array_slice($f['exercises'], 0, 3) as $exercise) {
        $items[] = WorkoutRecommendationModel::query()->create([
            'user_id' => $f['user']->id,
            'workout_analysis_id' => $analysis->id,
            'workout_session_id' => $f['session']->id,
            'training_program_id' => $f['program']->id,
            'exercise_id' => $exercise->id,
            'source_revision' => 0,
            'change_type' => 'progression',
            'status' => 'proposed',
            'original_sets' => [],
            'proposed_sets' => [],
            'rationale' => 'Изменить вес',
            'evidence' => [],
            'source_completed_at' => now()->subDay(),
        ]);
    }
    $replace($f, 2, true);
    expect($items[0]->refresh()->status)->toBe('expired');
    expect($items[1]->refresh()->status)->toBe('proposed');
    expect($items[2]->refresh()->status)->toBe('expired');
});

it('increments a returning temporary target baseline once', function () use ($fixture, $replace): void {
    $f = $fixture();
    $replace($f, 2, false);
    $replace($f, 0, true, 2);
    expect(DB::table('workout_plan_change_barriers')->where('exercise_id', $f['exercises'][0]->id)->value('revision'))->toBe(1);
    $this->assertDatabaseCount('workout_plan_change_barriers', 1);
});

it('replays a previous operation after another replacement and catalog deletion', function () use ($fixture, $replace): void {
    $f = $fixture();
    $replace($f, 2, false);
    $replace($f, 3, false, 2);
    $f['exercises'][2]->delete();
    $replace($f, 2, false);
    $session = app(WorkoutSessionRepository::class)->findForUser(
        new WorkoutSessionId($f['session']->id),
        new UserId($f['user']->id),
    ) ?? throw new LogicException('Missing session.');
    expect($session->workoutExercises()[0]->effectiveSnapshot()->exerciseId->value)->toBe($f['exercises'][3]->id);
    $this->assertDatabaseCount('workout_exercise_replacements', 2);
});
