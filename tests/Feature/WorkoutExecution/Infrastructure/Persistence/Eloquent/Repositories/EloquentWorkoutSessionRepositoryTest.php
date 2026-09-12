<?php

use App\Models\User;
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
use App\WorkoutExecution\Domain\ValueObjects\PlannedPrescription;
use App\WorkoutExecution\Domain\ValueObjects\ProgramName;
use App\WorkoutExecution\Domain\ValueObjects\Repetitions;
use App\WorkoutExecution\Domain\ValueObjects\SetPosition;
use App\WorkoutExecution\Domain\ValueObjects\SetsCount;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Domain\ValueObjects\WorkingWeight;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSet;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Mappers\WorkoutSessionMapper;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Repositories\EloquentWorkoutSessionRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

$repository = fn (): EloquentWorkoutSessionRepository => new EloquentWorkoutSessionRepository(
    new WorkoutSessionMapper,
    app(DatabaseManager::class),
);

$workoutExercise = static fn (
    int $exerciseId,
    string $name,
    int $position,
    int $sets,
    int $repetitions,
    int $weightInGrams,
): WorkoutExercise => WorkoutExercise::fromPlan(
    new ExerciseSnapshot(
        new ExerciseId($exerciseId),
        new ExerciseName($name),
        new ExercisePosition($position),
    ),
    new PlannedPrescription(
        new SetsCount($sets),
        new Repetitions($repetitions),
        new WorkingWeight($weightInGrams),
    ),
);

$newSession = static fn (int $userId, int $trainingProgramId = 71): WorkoutSession => WorkoutSession::start(
    new UserId($userId),
    new TrainingProgramSnapshot(
        new TrainingProgramId($trainingProgramId),
        new ProgramName('Грудь и трицепс'),
        ScheduledWeekday::Monday,
    ),
    new WorkoutExerciseCollection(
        $workoutExercise(501, 'Жим лежа', 1, 3, 8, 90_000),
        $workoutExercise(502, 'Разгибание на трицепс', 2, 3, 12, 36_000),
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
        'planned_sets' => 3,
        'planned_repetitions_per_set' => 8,
        'planned_working_weight_grams' => 90_000,
        'position' => 1,
        'status' => 'pending',
    ]);
    $this->assertDatabaseCount('workout_sets', 6);

    $rehydrated = $workoutSessions->findForUser($id, new UserId($user->id));

    expect($rehydrated?->startedAt->getTimezone()->getName())->toBe('Europe/Moscow');
    expect($rehydrated?->startedAt->format('Y-m-d H:i:s'))->toBe('2026-09-15 19:00:00');
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
    expect($exercises[0]->plannedPrescription->workingWeight->grams)->toBe(90_000);
    expect($exercises[0]->workoutSets())->toHaveCount(4);
    expect($exercises[0]->workoutSets()[1]->workingWeight->grams)->toBe(100_000);
    expect($exercises[1]->status->value)->toBe('completed');
    expect($exercises[1]->workoutSets())->toHaveCount(1);
    $this->assertDatabaseCount('workout_sets', 5);
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
