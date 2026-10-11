<?php

use App\Models\Exercise;
use App\Models\User;
use App\WorkoutAnalysis\Application\UseCases\ActOnWorkoutRecommendation\ActOnWorkoutRecommendation;
use App\WorkoutAnalysis\Application\UseCases\ActOnWorkoutRecommendation\ActOnWorkoutRecommendationInput;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\CompletedWorkoutSnapshotCodec;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutAnalysisModel;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutRecommendationModel;
use App\WorkoutAnalysis\Infrastructure\Queue\CalculateWorkoutDeviationsJob;
use App\WorkoutExecution\Application\DTO\WorkoutSetInput;
use App\WorkoutExecution\Application\Gateways\WorkoutClock;
use App\WorkoutExecution\Application\UseCases\CompleteExercise\CompleteExercise;
use App\WorkoutExecution\Application\UseCases\CompleteExercise\CompleteExerciseInput;
use App\WorkoutExecution\Application\UseCases\CompleteWorkoutSession\CompleteWorkoutSession;
use App\WorkoutExecution\Application\UseCases\CompleteWorkoutSession\CompleteWorkoutSessionInput;
use App\WorkoutExecution\Application\UseCases\ReplaceExercise\ReplaceExercise;
use App\WorkoutExecution\Application\UseCases\ReplaceExercise\ReplaceExerciseInput;
use App\WorkoutExecution\Application\UseCases\SaveExerciseProgress\SaveExerciseProgress;
use App\WorkoutExecution\Application\UseCases\SaveExerciseProgress\SaveExerciseProgressInput;
use App\WorkoutExecution\Application\UseCases\SkipExercise\SkipExercise;
use App\WorkoutExecution\Application\UseCases\SkipExercise\SkipExerciseInput;
use App\WorkoutExecution\Application\UseCases\StartWorkoutSession\StartWorkoutSession;
use App\WorkoutExecution\Application\UseCases\StartWorkoutSession\StartWorkoutSessionInput;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSessionModel;
use App\WorkoutPlanning\Application\UseCases\DeleteTrainingProgram\DeleteTrainingProgram;
use App\WorkoutPlanning\Application\UseCases\DeleteTrainingProgram\DeleteTrainingProgramInput;
use App\WorkoutPlanning\Application\UseCases\UpdateTrainingProgram\UpdateTrainingProgram;
use App\WorkoutPlanning\Application\UseCases\UpdateTrainingProgram\UpdateTrainingProgramInput;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\TrainingProgramModel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture;
use Tests\Support\WorkoutExecution\FrozenWorkoutClock;
use Tests\Support\WorkoutPlanning\PlannedExerciseFixture;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->travelTo(new DateTimeImmutable('2026-09-21T12:00:00Z'));
    app()->instance(WorkoutClock::class, new FrozenWorkoutClock(now()->toDateTimeImmutable()));
    config(['workout-execution.mutation_lock.store' => 'array', 'workout-planning.mutation_lock.store' => 'array']);
});

/** @return array{userId:int, programId:int, sessionId:int, sourceId:int, otherId:int, candidateId:int, recommendationId:int|null} */
function createConcurrentWorkoutReplacementFixture(bool $withRecommendation): array
{
    $user = User::factory()->create();
    [$source, $other, $candidate] = Exercise::factory()->count(3)->create()->all();
    $program = TrainingProgramModel::query()->create(['user_id' => $user->id, 'weekday' => 1, 'name' => 'Программа']);
    foreach ([$source, $other] as $index => $exercise) {
        $program->plannedExercises()->create(['exercise_id' => $exercise->id, 'position' => $index + 1])
            ->plannedSets()->create(['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 50_000]);
    }
    $recommendationId = null;
    if ($withRecommendation) {
        $historical = WorkoutSessionModel::query()->create([
            'user_id' => $user->id, 'training_program_id' => $program->id, 'training_program_name' => $program->name,
            'scheduled_weekday' => 1, 'status' => 'completed', 'started_at' => now()->subDay()->subHour(), 'completed_at' => now()->subDay(),
        ]);
        $snapshot = WorkoutAnalysisFixture::result(
            sessionId: $historical->id, programId: $program->id, userId: $user->id,
            exercise: WorkoutAnalysisFixture::exercise(id: $other->id),
        )->snapshot;
        $analysis = WorkoutAnalysisModel::query()->create([
            'user_id' => $user->id, 'workout_session_id' => $historical->id,
            'snapshot' => app(CompletedWorkoutSnapshotCodec::class)->encode($snapshot), 'snapshot_version' => 2,
        ]);
        $recommendationId = WorkoutRecommendationModel::query()->create([
            'user_id' => $user->id, 'workout_analysis_id' => $analysis->id,
            'workout_session_id' => $historical->id, 'training_program_id' => $program->id,
            'exercise_id' => $other->id, 'source_revision' => 0, 'change_type' => 'progression', 'status' => 'proposed',
            'original_sets' => [['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 50_000]],
            'proposed_sets' => [['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 55_000]],
            'rationale' => 'План выполнен.', 'evidence' => [], 'source_completed_at' => now()->subDay(),
        ])->id;
    }
    $session = app(StartWorkoutSession::class)->handle(new StartWorkoutSessionInput($user->id, $program->id));

    return [
        'userId' => $user->id, 'programId' => $program->id, 'sessionId' => $session->id,
        'sourceId' => $source->id, 'otherId' => $other->id, 'candidateId' => $candidate->id, 'recommendationId' => $recommendationId,
    ];
}

/** @param array{userId:int, programId:int, sessionId:int, sourceId:int, otherId:int, candidateId:int, recommendationId:int|null} $fixture */
function replaceConcurrentWorkoutExercise(array $fixture): void
{
    app(ReplaceExercise::class)->handle(new ReplaceExerciseInput(
        $fixture['userId'], $fixture['sessionId'], $fixture['sourceId'],
        '00000000-0000-4000-8000-000000000001', $fixture['candidateId'], [new WorkoutSetInput(8, 60_000)], true,
    ));
}

/** @param array{userId:int, programId:int, sessionId:int, sourceId:int, otherId:int, candidateId:int, recommendationId:int|null} $fixture */
function resolveConcurrentWorkoutExercises(array $fixture): void
{
    app(CompleteExercise::class)->handle(new CompleteExerciseInput(
        $fixture['userId'], $fixture['sessionId'], $fixture['sourceId'], [new WorkoutSetInput(8, 60_000)],
    ));
    app(SkipExercise::class)->handle(new SkipExerciseInput($fixture['userId'], $fixture['sessionId'], $fixture['otherId']));
}

/** @param array{userId:int, programId:int, sessionId:int, sourceId:int, otherId:int, candidateId:int, recommendationId:int|null} $fixture */
function runConcurrentWorkoutCommand(string $command, array $fixture, bool $afterReplacement): void
{
    match ($command) {
        'save' => app(SaveExerciseProgress::class)->handle(new SaveExerciseProgressInput(
            $fixture['userId'], $fixture['sessionId'], $fixture['sourceId'], [new WorkoutSetInput(9, 65_000)],
        )),
        'complete' => app(CompleteWorkoutSession::class)->handle(new CompleteWorkoutSessionInput($fixture['userId'], $fixture['sessionId'])),
        'edit plan' => app(UpdateTrainingProgram::class)->handle(new UpdateTrainingProgramInput(
            $fixture['userId'], $fixture['programId'], 'Изменённый план', [
                PlannedExerciseFixture::input($afterReplacement ? $fixture['candidateId'] : $fixture['sourceId'], 1, $afterReplacement ? 8 : 10, $afterReplacement ? 60_000 : 50_000),
                PlannedExerciseFixture::input($fixture['otherId'], 1, 10, 55_000),
            ],
        )),
        'delete plan' => app(DeleteTrainingProgram::class)->handle(new DeleteTrainingProgramInput($fixture['userId'], $fixture['programId'])),
        'apply recommendation' => app(ActOnWorkoutRecommendation::class)->handle(
            new ActOnWorkoutRecommendationInput($fixture['userId'], $fixture['recommendationId'] ?? throw new LogicException('Missing recommendation.')), 'apply',
        ),
        default => throw new LogicException('Unknown concurrent command.'),
    };
}

function expectConcurrentWorkoutUserLockTimeout(Closure $command): void
{
    try {
        $command();
    } catch (QueryException $exception) {
        expect($exception->getSql())->toContain('"users"', 'for update');
        expect($exception->errorInfo[0] ?? null)->toBe('55P03');

        return;
    }

    throw new LogicException('The competing command did not wait for the user row lock.');
}

it('holds replacement user lock until commit and then permits a competing command', function (string $command): void {
    Queue::fake([CalculateWorkoutDeviationsJob::class]);
    Http::preventStrayRequests();
    $fixture = createConcurrentWorkoutReplacementFixture($command === 'apply recommendation');
    $owner = DB::connection();
    $ownerName = DB::getDefaultConnection();
    $configuration = $owner->getConfig();
    if (! is_array($configuration)) {
        throw new LogicException('Missing owner connection configuration.');
    }
    config()->set('database.connections.replacement_contender', array_merge($configuration, ['name' => 'replacement_contender']));
    $contender = DB::connection('replacement_contender');
    $contender->statement("SET lock_timeout = '50ms'");
    $owner->beginTransaction();
    try {
        replaceConcurrentWorkoutExercise($fixture);
        if ($command === 'complete') {
            resolveConcurrentWorkoutExercises($fixture);
        }
        DB::setDefaultConnection('replacement_contender');

        expectConcurrentWorkoutUserLockTimeout(fn () => runConcurrentWorkoutCommand($command, $fixture, true));
        expect($contender->transactionLevel())->toBe(0);
        expect($contender->table('workout_exercise_replacements')->count())->toBe(0);
        expect($contender->table('planned_exercises')->where('training_program_id', $fixture['programId'])->where('position', 1)->value('exercise_id'))->toBe($fixture['sourceId']);
        $owner->commit();
        runConcurrentWorkoutCommand($command, $fixture, true);

        expect($contender->table('workout_exercise_replacements')->count())->toBe(1);
        if ($command === 'save') {
            expect($contender->table('workout_sets')->where('repetitions', 9)->value('working_weight_grams'))->toBe(65_000);
        } elseif ($command === 'complete') {
            expect($contender->table('workout_sessions')->where('id', $fixture['sessionId'])->value('status'))->toBe('completed');
            Queue::assertPushed(CalculateWorkoutDeviationsJob::class, 1);
        } elseif ($command === 'delete plan') {
            expect($contender->table('training_programs')->where('id', $fixture['programId'])->exists())->toBeFalse();
        } elseif ($command === 'edit plan') {
            expect($contender->table('training_programs')->where('id', $fixture['programId'])->value('name'))->toBe('Изменённый план');
        } else {
            expect($contender->table('workout_recommendations')->where('id', $fixture['recommendationId'])->value('status'))->toBe('applied');
        }
        if ($command !== 'complete') {
            Queue::assertNothingPushed();
        }
        Http::assertNothingSent();
    } finally {
        DB::setDefaultConnection($ownerName);
        if ($owner->transactionLevel() > 0) {
            $owner->rollBack();
        }
        DB::purge('replacement_contender');
    }
})->with(['save', 'complete', 'edit plan', 'delete plan', 'apply recommendation'])
    ->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'Requires PostgreSQL row locks.');

it('waits for a competing command user lock and replaces after its transaction rolls back', function (string $command): void {
    Queue::fake([CalculateWorkoutDeviationsJob::class]);
    Http::preventStrayRequests();
    $fixture = createConcurrentWorkoutReplacementFixture($command === 'apply recommendation');
    $owner = DB::connection();
    $ownerName = DB::getDefaultConnection();
    $configuration = $owner->getConfig();
    if (! is_array($configuration)) {
        throw new LogicException('Missing owner connection configuration.');
    }
    config()->set('database.connections.replacement_contender', array_merge($configuration, ['name' => 'replacement_contender']));
    $contender = DB::connection('replacement_contender');
    $contender->statement("SET lock_timeout = '50ms'");
    $owner->beginTransaction();
    try {
        if ($command === 'complete') {
            resolveConcurrentWorkoutExercises($fixture);
        }
        runConcurrentWorkoutCommand($command, $fixture, false);
        DB::setDefaultConnection('replacement_contender');

        expectConcurrentWorkoutUserLockTimeout(fn () => replaceConcurrentWorkoutExercise($fixture));
        expect($contender->transactionLevel())->toBe(0);
        $owner->rollBack();
        replaceConcurrentWorkoutExercise($fixture);

        expect($contender->table('workout_exercise_replacements')->count())->toBe(1);
        expect($contender->table('planned_exercises')->where('training_program_id', $fixture['programId'])->where('position', 1)->value('exercise_id'))->toBe($fixture['candidateId']);
        expect($contender->table('workout_sessions')->where('id', $fixture['sessionId'])->value('status'))->toBe('in_progress');
        expect($contender->table('workout_plan_change_barriers')->where('exercise_id', $fixture['otherId'])->count())->toBe(0);
        if ($command === 'apply recommendation') {
            expect($contender->table('workout_recommendations')->where('id', $fixture['recommendationId'])->value('status'))->toBe('proposed');
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    } finally {
        DB::setDefaultConnection($ownerName);
        if ($owner->transactionLevel() > 0) {
            $owner->rollBack();
        }
        DB::purge('replacement_contender');
    }
})->with(['save', 'complete', 'edit plan', 'delete plan', 'apply recommendation'])
    ->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'Requires PostgreSQL row locks.');
