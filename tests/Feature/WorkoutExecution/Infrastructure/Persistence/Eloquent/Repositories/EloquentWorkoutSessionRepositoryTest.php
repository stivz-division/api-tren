<?php

use App\Models\Exercise;
use App\Models\User;
use App\WorkoutAnalysis\Infrastructure\Integrations\WorkoutPlanning\RecommendationPlanInvalidator;
use App\WorkoutExecution\Domain\Collections\WorkoutExerciseCollection;
use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\Entities\WorkoutExercise;
use App\WorkoutExecution\Domain\Entities\WorkoutSession;
use App\WorkoutExecution\Domain\Enums\ScheduledWeekday;
use App\WorkoutExecution\Domain\Exceptions\ActiveWorkoutSessionAlreadyExists;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseName;
use App\WorkoutExecution\Domain\ValueObjects\ExercisePosition;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\ProgramName;
use App\WorkoutExecution\Domain\ValueObjects\Repetitions;
use App\WorkoutExecution\Domain\ValueObjects\SetPosition;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Domain\ValueObjects\WorkingWeight;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutExerciseReplacement;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSet;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Mappers\WorkoutSessionMapper;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutExerciseReplacementModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSessionModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Repositories\EloquentWorkoutSessionRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\WorkoutExecution\WorkoutSessionFixture;

uses(LazilyRefreshDatabase::class);

$repository = fn (): EloquentWorkoutSessionRepository => new EloquentWorkoutSessionRepository(
    new WorkoutSessionMapper,
    app(DatabaseManager::class),
    app(RecommendationPlanInvalidator::class),
);

$workoutExercise = static fn (
    int $exerciseId,
    string $name,
    int $position,
    int $sets,
    int $repetitions,
    int $weightInGrams,
    ?int $sourcePlanRevision = null,
): WorkoutExercise => WorkoutExercise::fromPlan(
    new ExerciseSnapshot(
        new ExerciseId($exerciseId),
        new ExerciseName($name),
        new ExercisePosition($position),
    ),
    WorkoutSessionFixture::sets($sets, $repetitions, $weightInGrams),
    $sourcePlanRevision,
);

$newSession = static fn (int $userId, int $trainingProgramId = 71, ?int $sourcePlanRevision = null): WorkoutSession => WorkoutSession::start(
    new UserId($userId),
    new TrainingProgramSnapshot(
        new TrainingProgramId($trainingProgramId),
        new ProgramName('Грудь и трицепс'),
        ScheduledWeekday::Monday,
    ),
    new WorkoutExerciseCollection(
        $workoutExercise(501, 'Жим лежа', 1, 3, 8, 90_000, $sourcePlanRevision),
        $workoutExercise(502, 'Разгибание на трицепс', 2, 3, 12, 36_000, $sourcePlanRevision),
    ),
    new DateTimeImmutable('2026-09-15 19:00:00', new DateTimeZone('Europe/Moscow')),
);

$actualSets = static fn (): WorkoutSetCollection => new WorkoutSetCollection(
    new WorkoutSet(new SetPosition(1), new Repetitions(8), new WorkingWeight(90_000)),
    new WorkoutSet(new SetPosition(2), new Repetitions(8), new WorkingWeight(100_000)),
    new WorkoutSet(new SetPosition(3), new Repetitions(7), new WorkingWeight(100_000)),
    new WorkoutSet(new SetPosition(4), new Repetitions(6), new WorkingWeight(95_000)),
);

it('persists and rehydrates the complete workout aggregate', function () use ($repository, $newSession): void {
    $user = User::factory()->create();
    $workoutSessions = $repository();

    $persisted = $workoutSessions->add($newSession($user->id));
    $id = $persisted->id ?? throw new LogicException('Сессия должна иметь идентификатор.');

    $this->assertDatabaseHas('workout_sessions', [
        'id' => $id->value,
        'user_id' => $user->id,
        'training_program_id' => 71,
        'training_program_name' => 'Грудь и трицепс',
        'scheduled_weekday' => 1,
        'status' => 'in_progress',
    ]);
    $this->assertDatabaseHas('workout_exercises', [
        'workout_session_id' => $id->value,
        'exercise_id' => 501,
        'exercise_name' => 'Жим лежа',
        'position' => 1,
        'status' => 'pending',
    ]);
    $this->assertDatabaseCount('workout_planned_sets', 6);
    $this->assertDatabaseHas('workout_planned_sets', [
        'position' => 3,
        'repetitions' => 8,
        'working_weight_grams' => 90_000,
    ]);
    $this->assertDatabaseCount('workout_sets', 6);

    $rehydrated = $workoutSessions->findForUser($id, new UserId($user->id));

    expect($rehydrated?->startedAt->getTimezone()->getName())->toBe('UTC');
    expect($rehydrated?->startedAt->format('Y-m-d H:i:s'))->toBe('2026-09-15 16:00:00');
    expect($rehydrated?->workoutExercises())->toHaveCount(2);
    expect($rehydrated?->workoutExercises()[0]->workoutSets())->toHaveCount(3);
});

it('enforces one active workout session per user atomically', function () use ($repository, $newSession): void {
    $user = User::factory()->create();
    $workoutSessions = $repository();
    $workoutSessions->add($newSession($user->id, 71));

    expect(fn () => $workoutSessions->add($newSession($user->id, 72)))
        ->toThrow(ActiveWorkoutSessionAlreadyExists::class);
    $this->assertDatabaseCount('workout_sessions', 1);
});

it('replaces actual sets while preserving immutable plan snapshots', function () use ($repository, $newSession, $actualSets): void {
    $user = User::factory()->create();
    $workoutSessions = $repository();
    $session = $workoutSessions->add($newSession($user->id));
    $session->saveExerciseProgress(new ExerciseId(501), $actualSets());
    $session->completeExercise(
        new ExerciseId(502),
        new WorkoutSetCollection(new WorkoutSet(
            new SetPosition(1),
            new Repetitions(10),
            new WorkingWeight(40_000),
        )),
    );

    $workoutSessions->save($session);

    $id = $session->id ?? throw new LogicException('Сессия должна иметь идентификатор.');
    $rehydrated = $workoutSessions->findForUser($id, new UserId($user->id));
    $exercises = $rehydrated?->workoutExercises() ?? [];

    expect($exercises[0]->snapshot->name->value)->toBe('Жим лежа');
    expect($exercises[0]->plannedSets())->toHaveCount(3);
    expect($exercises[0]->plannedSets()[2]->workingWeight->grams)->toBe(90_000);
    expect($exercises[0]->workoutSets())->toHaveCount(4);
    expect($exercises[0]->workoutSets()[1]->workingWeight->grams)->toBe(100_000);
    expect($exercises[1]->status->value)->toBe('completed');
    expect($exercises[1]->workoutSets())->toHaveCount(1);
    $this->assertDatabaseCount('workout_sets', 5);
    $this->assertDatabaseCount('workout_planned_sets', 6);
});

it('allows a new active session after the previous session is completed', function () use ($repository, $newSession): void {
    $user = User::factory()->create();
    $workoutSessions = $repository();
    $first = $workoutSessions->add($newSession($user->id, 71));
    $firstExercise = $first->workoutExercises()[0];
    $first->completeExercise(
        new ExerciseId(501),
        new WorkoutSetCollection(...$firstExercise->workoutSets()),
    );
    $first->skipExercise(new ExerciseId(502));
    $first->complete(new DateTimeImmutable('2026-09-15 20:00:00', new DateTimeZone('Europe/Moscow')));
    $workoutSessions->save($first);

    $second = $workoutSessions->add($newSession($user->id, 72));

    expect($workoutSessions->findActiveForUser(new UserId($user->id))?->id?->value)
        ->toBe($second->id?->value);
    $this->assertDatabaseCount('workout_sessions', 2);
});

it('does not expose a workout session to another user', function () use ($repository, $newSession): void {
    $owner = User::factory()->create();
    $anotherUser = User::factory()->create();
    $workoutSessions = $repository();
    $session = $workoutSessions->add($newSession($owner->id));
    $id = $session->id ?? throw new LogicException('Сессия должна иметь идентификатор.');

    expect($workoutSessions->findForUser($id, new UserId($anotherUser->id)))->toBeNull();
});

$replacement = static fn (int $sequence = 1, int $exerciseId = 601, bool $replaceInPlan = true): WorkoutExerciseReplacement => new WorkoutExerciseReplacement(
    $sequence,
    $sequence === 1 ? '550e8400-e29b-41d4-a716-446655440000' : '550e8400-e29b-41d4-a716-446655440001',
    new ExerciseSnapshot(new ExerciseId($exerciseId), new ExerciseName('Новое упражнение'), new ExercisePosition(1)),
    WorkoutSessionFixture::sets(2, 10, 35_000),
    $replaceInPlan,
    new DateTimeImmutable('2026-09-15 16:15:00.123456 UTC'),
    $replaceInPlan ? 4 : null,
);

it('round trips mixed replacements without rewriting the original snapshot or stored replacements', function () use ($repository, $newSession, $replacement): void {
    $user = User::factory()->create();
    $workoutSessions = $repository();
    $session = $workoutSessions->add($newSession($user->id));
    $session->replaceExercise(new ExerciseId(501), $replacement());
    $workoutSessions->save($session);
    $storedId = WorkoutExerciseReplacementModel::query()->sole()->id;
    $session->replaceExercise(new ExerciseId(501), $replacement(2, 602, false));
    $session->saveExerciseProgress(new ExerciseId(501), WorkoutSessionFixture::sets(1, 7, 37_000));

    $workoutSessions->save($session);
    $workoutSessions->save($session);

    $id = $session->id ?? throw new LogicException('Сессия должна иметь идентификатор.');
    $exercise = $workoutSessions->findForUser($id, new UserId($user->id))?->workoutExercises()[0]
        ?? throw new LogicException('Упражнение должно быть найдено.');
    expect($exercise->snapshot->exerciseId->value)->toBe(501);
    expect($exercise->plannedSets()[0]->workingWeight->grams)->toBe(90_000);
    expect($exercise->effectiveSnapshot()->exerciseId->value)->toBe(602);
    expect($exercise->effectivePlannedSets())->toHaveCount(2);
    expect($exercise->workoutSets()[0]->workingWeight->grams)->toBe(37_000);
    expect($exercise->planBaselineSnapshot()->exerciseId->value)->toBe(601);
    expect($exercise->planBaselineRevision())->toBe(4);
    expect($exercise->replacements()[0]->createdAt->format('Y-m-d H:i:s.uP'))->toBe('2026-09-15 16:15:00.123456+00:00');
    expect(WorkoutExerciseReplacementModel::query()->orderBy('sequence')->first()?->id)->toBe($storedId);
    $this->assertDatabaseCount('workout_exercise_replacements', 2);
    $this->assertDatabaseCount('workout_replacement_planned_sets', 4);
    $this->assertDatabaseCount('workout_planned_sets', 6);
});

it('preserves legacy null revisions and empty replacement history', function () use ($repository, $newSession): void {
    $user = User::factory()->create();
    $workoutSessions = $repository();
    $session = $workoutSessions->add($newSession($user->id));
    $id = $session->id ?? throw new LogicException('Сессия должна иметь идентификатор.');

    $exercise = $workoutSessions->findActiveForUser(new UserId($user->id))?->workoutExercises()[0];

    expect($exercise?->sourcePlanRevision)->toBeNull();
    expect($exercise?->replacements())->toBe([]);
    $this->assertDatabaseHas('workout_exercises', ['workout_session_id' => $id->value, 'source_plan_revision' => null]);
});

it('rejects changed or removed saved replacement history without changing progress', function (string $change) use ($repository, $newSession, $replacement): void {
    $user = User::factory()->create();
    $workoutSessions = $repository();
    $session = $workoutSessions->add($newSession($user->id));
    $session->replaceExercise(new ExerciseId(501), $replacement());
    $workoutSessions->save($session);
    $original = $session->workoutExercises()[0];
    $savedReplacement = $original->replacements()[0];
    $history = $change === 'removed' ? [] : [new WorkoutExerciseReplacement(
        1,
        $change === 'request id' ? '550e8400-e29b-41d4-a716-446655440001' : $savedReplacement->requestId,
        new ExerciseSnapshot(new ExerciseId($change === 'exercise id' ? 603 : 601), new ExerciseName($change === 'name' ? 'Изменённый снимок' : 'Новое упражнение'), new ExercisePosition(1)),
        WorkoutSessionFixture::sets(2, 10, $change === 'sets' ? 36_000 : 35_000),
        $change !== 'mode',
        $change === 'timestamp' ? $savedReplacement->createdAt->modify('+1 microsecond') : $savedReplacement->createdAt,
        $change === 'mode' ? null : ($change === 'revision' ? 5 : 4),
    )];
    $altered = WorkoutSession::restore(
        $session->id ?? throw new LogicException('Сессия должна иметь идентификатор.'),
        $session->userId,
        $session->programSnapshot,
        new WorkoutExerciseCollection(
            WorkoutExercise::restore($original->snapshot, new WorkoutSetCollection(...$original->plannedSets()), WorkoutSessionFixture::sets(1, 1, 1_000), $original->status, $history),
            $session->workoutExercises()[1],
        ),
        $session->status,
        $session->startedAt,
        null,
        null,
    );

    expect(fn () => $workoutSessions->save($altered))->toThrow(LogicException::class);

    $this->assertDatabaseCount('workout_exercise_replacements', 1);
    $this->assertDatabaseCount('workout_sets', 5);
    $this->assertDatabaseHas('workout_exercise_replacements', ['exercise_name' => 'Новое упражнение']);
})->with(['name', 'exercise id', 'sets', 'mode', 'timestamp', 'revision', 'request id', 'removed']);

it('round trips the revision captured at session start', function (int $revision) use ($repository, $newSession): void {
    $user = User::factory()->create();
    $workoutSessions = $repository();
    $session = $workoutSessions->add($newSession($user->id, 71, $revision));

    $workoutSessions->save($session);

    expect($workoutSessions->findActiveForUser(new UserId($user->id))?->workoutExercises()[0]->sourcePlanRevision)->toBe($revision);
    $this->assertDatabaseHas('workout_exercises', ['exercise_id' => 501, 'source_plan_revision' => $revision]);
})->with(['initial plan' => 0, 'changed plan' => 7]);

it('rejects changes to the original exercise snapshot after replacement', function (string $change) use ($repository, $newSession, $replacement): void {
    $user = User::factory()->create();
    $workoutSessions = $repository();
    $session = $workoutSessions->add($newSession($user->id));
    $session->replaceExercise(new ExerciseId(501), $replacement());
    $workoutSessions->save($session);
    $exercise = $session->workoutExercises()[0];
    $altered = WorkoutSession::restore(
        $session->id ?? throw new LogicException('Сессия должна иметь идентификатор.'),
        $session->userId,
        $session->programSnapshot,
        new WorkoutExerciseCollection(
            WorkoutExercise::restore(
                new ExerciseSnapshot(new ExerciseId(501), new ExerciseName($change === 'name' ? 'Изменённый снимок' : 'Жим лежа'), new ExercisePosition(1)),
                $change === 'sets' ? WorkoutSessionFixture::sets(3, 8, 91_000) : new WorkoutSetCollection(...$exercise->plannedSets()),
                new WorkoutSetCollection(...$exercise->workoutSets()),
                $exercise->status,
                $exercise->replacements(),
            ),
            $session->workoutExercises()[1],
        ),
        $session->status,
        $session->startedAt,
        null,
        null,
    );

    expect(fn () => $workoutSessions->save($altered))->toThrow(LogicException::class);
})->with(['name', 'sets']);

it('rejects changes to the source revision', function () use ($repository, $newSession): void {
    $user = User::factory()->create();
    $workoutSessions = $repository();
    $session = $workoutSessions->add($newSession($user->id));
    $exercise = $session->workoutExercises()[0];
    $altered = WorkoutSession::restore(
        $session->id ?? throw new LogicException('Сессия должна иметь идентификатор.'),
        $session->userId,
        $session->programSnapshot,
        new WorkoutExerciseCollection(
            WorkoutExercise::fromPlan($exercise->snapshot, new WorkoutSetCollection(...$exercise->plannedSets()), 0),
            $session->workoutExercises()[1],
        ),
        $session->status,
        $session->startedAt,
        null,
        null,
    );

    expect(fn () => $workoutSessions->save($altered))->toThrow(LogicException::class);
});

it('rehydrates replacements after the catalog exercise is deleted', function () use ($repository, $newSession, $replacement): void {
    $user = User::factory()->create();
    $catalogExercise = Exercise::factory()->create(['name' => 'Новое упражнение']);
    $workoutSessions = $repository();
    $session = $workoutSessions->add($newSession($user->id));
    $session->replaceExercise(new ExerciseId(501), $replacement(1, $catalogExercise->id));
    $workoutSessions->save($session);

    $catalogExercise->delete();

    $exercise = $workoutSessions->findActiveForUser(new UserId($user->id))?->workoutExercises()[0];
    expect($exercise?->effectiveSnapshot()->name->value)->toBe('Новое упражнение');
    expect($exercise?->effectiveSnapshot()->exerciseId->value)->toBe($catalogExercise->id);
});

it('rolls back appended replacements and progress when saving fails', function () use ($repository, $newSession, $replacement): void {
    $user = User::factory()->create();
    $workoutSessions = $repository();
    $session = $workoutSessions->add($newSession($user->id));
    $session->replaceExercise(new ExerciseId(501), $replacement());
    $connection = app(DatabaseManager::class)->connection();
    if ($connection->getDriverName() === 'pgsql') {
        $connection->statement("CREATE FUNCTION pg_temp.fail_workout_set() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'forced persistence failure'; END; $$");
        $connection->statement('CREATE TRIGGER fail_workout_set BEFORE INSERT ON workout_sets FOR EACH ROW EXECUTE FUNCTION pg_temp.fail_workout_set()');
    } else {
        $connection->statement("CREATE TRIGGER fail_workout_set BEFORE INSERT ON workout_sets BEGIN SELECT RAISE(ABORT, 'forced persistence failure'); END");
    }

    try {
        expect(fn () => $workoutSessions->save($session))->toThrow(QueryException::class);
    } finally {
        $connection->statement($connection->getDriverName() === 'pgsql' ? 'DROP TRIGGER fail_workout_set ON workout_sets' : 'DROP TRIGGER fail_workout_set');
    }

    $this->assertDatabaseCount('workout_exercise_replacements', 0);
    $this->assertDatabaseCount('workout_replacement_planned_sets', 0);
    $this->assertDatabaseCount('workout_sets', 6);
});

it('rejects duplicate replacement sequences and request ids per exercise', function (string $duplicate) use ($repository, $newSession, $replacement): void {
    $user = User::factory()->create();
    $workoutSessions = $repository();
    $session = $workoutSessions->add($newSession($user->id));
    $session->replaceExercise(new ExerciseId(501), $replacement());
    $workoutSessions->save($session);
    $stored = WorkoutExerciseReplacementModel::query()->sole();
    $attributes = $stored->getAttributes();
    unset($attributes['id']);
    $attributes['sequence'] = $duplicate === 'sequence' ? 1 : 2;
    $attributes['request_id'] = $duplicate === 'request id' ? $stored->request_id : '550e8400-e29b-41d4-a716-446655440001';

    expect(fn () => app(DatabaseManager::class)->table('workout_exercise_replacements')->insert($attributes))->toThrow(QueryException::class);
})->with(['sequence', 'request id']);

it('rejects duplicate replacement planned set positions', function () use ($repository, $newSession, $replacement): void {
    $user = User::factory()->create();
    $workoutSessions = $repository();
    $session = $workoutSessions->add($newSession($user->id));
    $session->replaceExercise(new ExerciseId(501), $replacement());
    $workoutSessions->save($session);
    $stored = WorkoutExerciseReplacementModel::query()->sole();

    expect(fn () => $stored->plannedSets()->create(['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 35_000]))->toThrow(QueryException::class);
});

it('requires explicitly loaded replacement relations when restoring a session', function (string $missing) use ($repository, $newSession, $replacement): void {
    $user = User::factory()->create();
    $workoutSessions = $repository();
    $session = $workoutSessions->add($newSession($user->id));
    $session->replaceExercise(new ExerciseId(501), $replacement());
    $workoutSessions->save($session);
    $model = WorkoutSessionModel::query()->with('workoutExercises.plannedSets', 'workoutExercises.workoutSets', 'workoutExercises.replacements.plannedSets')->sole();
    $exercise = $model->workoutExercises->firstOrFail();
    if ($missing === 'replacements') {
        $exercise->unsetRelation('replacements');
    } else {
        $exercise->replacements->firstOrFail()->unsetRelation('plannedSets');
    }

    expect(fn () => (new WorkoutSessionMapper)->toDomain($model))->toThrow(LogicException::class);
})->with(['replacements', 'replacement sets']);
