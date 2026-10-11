<?php

use App\WorkoutExecution\Domain\Collections\WorkoutExerciseCollection;
use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\Entities\WorkoutExercise;
use App\WorkoutExecution\Domain\Entities\WorkoutSession;
use App\WorkoutExecution\Domain\Enums\ScheduledWeekday;
use App\WorkoutExecution\Domain\Enums\WorkoutExerciseStatus;
use App\WorkoutExecution\Domain\Enums\WorkoutSessionStatus;
use App\WorkoutExecution\Domain\Exceptions\ExerciseAlreadyAddedToWorkout;
use App\WorkoutExecution\Domain\Exceptions\InvalidWorkoutSessionState;
use App\WorkoutExecution\Domain\Exceptions\WorkoutExerciseNotFound;
use App\WorkoutExecution\Domain\Exceptions\WorkoutReplacementRequestConflict;
use App\WorkoutExecution\Domain\Exceptions\WorkoutResolutionBeforeStart;
use App\WorkoutExecution\Domain\Exceptions\WorkoutSessionHasPendingExercises;
use App\WorkoutExecution\Domain\Exceptions\WorkoutSessionIsNotInProgress;
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
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSet;
use Tests\Support\WorkoutExecution\WorkoutSessionFixture;

$workoutExercise = static fn (int $exerciseId, int $position): WorkoutExercise => WorkoutExercise::fromPlan(
    new ExerciseSnapshot(
        new ExerciseId($exerciseId),
        new ExerciseName('Упражнение '.$exerciseId),
        new ExercisePosition($position),
    ),
    WorkoutSessionFixture::sets(),
);

$startedAt = new DateTimeImmutable('2026-09-15 19:00:00', new DateTimeZone('Europe/Moscow'));

$createSession = static fn (): WorkoutSession => WorkoutSession::start(
    new UserId(42),
    new TrainingProgramSnapshot(
        new TrainingProgramId(7),
        new ProgramName('Понедельник — грудь'),
        ScheduledWeekday::Monday,
    ),
    new WorkoutExerciseCollection(
        $workoutExercise(10, 1),
        $workoutExercise(20, 2),
    ),
    $startedAt,
);

$singleSet = static fn (int $repetitions, int $weight): WorkoutSetCollection => new WorkoutSetCollection(
    new WorkoutSet(
        new SetPosition(1),
        new Repetitions($repetitions),
        new WorkingWeight($weight),
    ),
);

$replacement = static fn (int $candidate = 30, int $sequence = 1): WorkoutExerciseReplacement => new WorkoutExerciseReplacement(
    $sequence,
    sprintf('550e8400-e29b-41d4-a716-%012d', $sequence),
    new ExerciseSnapshot(new ExerciseId($candidate), new ExerciseName('Новое упражнение'), new ExercisePosition(1)),
    new WorkoutSetCollection(new WorkoutSet(new SetPosition(1), new Repetitions(10), new WorkingWeight(25_000))),
    false,
    new DateTimeImmutable('2026-09-15 20:00:00 UTC'),
);

it('continues exercise commands using the source catalogue ID after replacement', function () use ($createSession, $replacement, $singleSet) {
    $session = $createSession();
    $session->replaceExercise(new ExerciseId(10), $replacement());

    $session->completeExercise(new ExerciseId(10), $singleSet(8, 30_000));

    expect($session->workoutExercises()[0]->snapshot->exerciseId->value)->toBe(10);
    expect($session->workoutExercises()[0]->effectiveSnapshot()->exerciseId->value)->toBe(30);
    expect($session->workoutExercises()[0]->status)->toBe(WorkoutExerciseStatus::Completed);
    expect(fn () => $session->reopenExercise(new ExerciseId(30)))->toThrow(WorkoutExerciseNotFound::class);
});

it('replays replacements after completion without resetting exercise progress', function () use ($createSession, $replacement, $singleSet, $startedAt) {
    $session = $createSession();
    $record = $replacement();
    $session->replaceExercise(new ExerciseId(10), $record);
    $session->completeExercise(new ExerciseId(10), $singleSet(8, 30_000));
    $session->skipExercise(new ExerciseId(20));
    $session->complete($startedAt->modify('+1 hour'));

    expect($session->replaceExercise(new ExerciseId(10), $record))->toBeFalse();
    expect($session->isReplacementReplay(new ExerciseId(10), $record->requestId, new ExerciseId(30), false, new WorkoutSetCollection(...$record->plannedSets())))->toBeTrue();
    expect($session->workoutExercises()[0]->workoutSets()[0]->workingWeight->grams)->toBe(30_000);
    expect($session->status)->toBe(WorkoutSessionStatus::Completed);
    expect(fn () => $session->replaceExercise(new ExerciseId(10), $replacement(40, 2)))->toThrow(WorkoutSessionIsNotInProgress::class);
    expect(fn () => $session->isReplacementReplay(new ExerciseId(10), $record->requestId, new ExerciseId(40), false, new WorkoutSetCollection(...$record->plannedSets())))->toThrow(WorkoutReplacementRequestConflict::class);
});

it('rejects candidates already effective in another position without changing state', function () use ($createSession, $replacement) {
    $session = $createSession();

    expect(fn () => $session->assertCanReplaceExercise(new ExerciseId(10), new ExerciseId(20)))->toThrow(ExerciseAlreadyAddedToWorkout::class);
    expect(fn () => $session->replaceExercise(new ExerciseId(10), $replacement(20)))->toThrow(ExerciseAlreadyAddedToWorkout::class);
    expect($session->workoutExercises()[0]->replacements())->toBe([]);
});

it('rejects duplicating a replacement from another position', function () use ($createSession, $replacement) {
    $session = $createSession();
    $record = $replacement();
    $session->replaceExercise(new ExerciseId(10), $record);
    $duplicate = new WorkoutExerciseReplacement(
        1, $record->requestId,
        new ExerciseSnapshot(new ExerciseId(30), new ExerciseName('Новое'), new ExercisePosition(2)),
        new WorkoutSetCollection(...$record->plannedSets()), false, $record->createdAt,
    );

    expect(fn () => $session->replaceExercise(new ExerciseId(20), $duplicate))->toThrow(ExerciseAlreadyAddedToWorkout::class);
    expect($session->workoutExercises()[1]->replacements())->toBe([]);
});

it('protects replacements and progress from mutations through exposed exercise copies', function () use ($createSession, $replacement, $singleSet) {
    $session = $createSession();
    $session->replaceExercise(new ExerciseId(10), $replacement());
    $copy = $session->workoutExercises()[0];

    $copy->replace($replacement(40, 2));
    $copy->saveProgress($singleSet(1, 1_000));

    expect($session->workoutExercises()[0]->effectiveSnapshot()->exerciseId->value)->toBe(30);
    expect($session->workoutExercises()[0]->replacements())->toHaveCount(1);
    expect($session->workoutExercises()[0]->workoutSets()[0]->workingWeight->grams)->toBe(25_000);
});

it('preserves the source plan revision when initializing a fresh session', function () use ($workoutExercise, $startedAt) {
    $original = $workoutExercise(10, 1);
    $exercise = WorkoutExercise::fromPlan($original->snapshot, new WorkoutSetCollection(...$original->plannedSets()), 7);
    $session = WorkoutSession::start(
        new UserId(42), new TrainingProgramSnapshot(new TrainingProgramId(7), new ProgramName('План'), ScheduledWeekday::Monday),
        new WorkoutExerciseCollection($exercise), $startedAt,
    );

    expect($session->workoutExercises()[0]->sourcePlanRevision)->toBe(7);
    expect($session->workoutExercises()[0]->planBaselineRevision())->toBe(7);
});

it('replays a recorded replacement after cancellation but rejects a new one', function () use ($createSession, $replacement, $startedAt) {
    $session = $createSession();
    $record = $replacement();
    $session->replaceExercise(new ExerciseId(10), $record);
    $session->cancel($startedAt->modify('+1 hour'));

    expect($session->replaceExercise(new ExerciseId(10), $record))->toBeFalse();
    expect(fn () => $session->replaceExercise(new ExerciseId(10), $replacement(40, 2)))
        ->toThrow(WorkoutSessionIsNotInProgress::class);
    expect($session->workoutExercises()[0]->replacements())->toHaveCount(1);
});

it('allows an exercise ID released by another position while preserving source addresses', function () use ($createSession, $replacement) {
    $session = $createSession();
    $firstRecord = $replacement();
    $session->replaceExercise(new ExerciseId(10), $firstRecord);
    $secondRecord = new WorkoutExerciseReplacement(
        1, $firstRecord->requestId,
        new ExerciseSnapshot(new ExerciseId(10), new ExerciseName('Освободившееся упражнение'), new ExercisePosition(2)),
        new WorkoutSetCollection(...$firstRecord->plannedSets()), false, $firstRecord->createdAt,
    );

    $session->replaceExercise(new ExerciseId(20), $secondRecord);
    $session->skipExercise(new ExerciseId(10));
    $session->completeExercise(new ExerciseId(20), new WorkoutSetCollection(...$secondRecord->plannedSets()));

    expect($session->workoutExercises()[0]->effectiveSnapshot()->exerciseId->value)->toBe(30);
    expect($session->workoutExercises()[1]->effectiveSnapshot()->exerciseId->value)->toBe(10);
    expect($session->workoutExercises()[0]->status)->toBe(WorkoutExerciseStatus::Skipped);
    expect($session->workoutExercises()[1]->status)->toBe(WorkoutExerciseStatus::Completed);
});

it('starts a session from a program snapshot on any actual weekday', function () use ($createSession) {
    $session = $createSession();

    expect($session->id)->toBeNull();
    expect($session->status)->toBe(WorkoutSessionStatus::InProgress);
    expect($session->programSnapshot->scheduledWeekday)->toBe(ScheduledWeekday::Monday);
    expect($session->startedAt->format('l'))->toBe('Tuesday');
    expect($session->startedAt->getTimezone()->getName())->toBe('Europe/Moscow');
    expect($session->workoutExercises())->toHaveCount(2);
});

it('always initializes fresh pending exercises from their plan', function () use ($workoutExercise, $startedAt) {
    $completedExercise = $workoutExercise(10, 1);
    $completedExercise->complete(new WorkoutSetCollection(...$completedExercise->workoutSets()));
    $emptyExercise = $workoutExercise(20, 2);
    $emptyExercise->saveProgress(new WorkoutSetCollection);

    $session = WorkoutSession::start(
        new UserId(42),
        new TrainingProgramSnapshot(
            new TrainingProgramId(7),
            new ProgramName('Понедельник — грудь'),
            ScheduledWeekday::Monday,
        ),
        new WorkoutExerciseCollection($completedExercise, $emptyExercise),
        $startedAt,
    );

    foreach ($session->workoutExercises() as $exercise) {
        expect($exercise->status)->toBe(WorkoutExerciseStatus::Pending);
        expect($exercise->workoutSets())->toHaveCount(3);
    }
});

it('protects session state from mutations through external exercise references', function () use ($workoutExercise, $startedAt) {
    $exercise = $workoutExercise(10, 1);
    $session = WorkoutSession::start(
        new UserId(42),
        new TrainingProgramSnapshot(
            new TrainingProgramId(7),
            new ProgramName('Понедельник — грудь'),
            ScheduledWeekday::Monday,
        ),
        new WorkoutExerciseCollection($exercise),
        $startedAt,
    );

    $exercise->complete(new WorkoutSetCollection(...$exercise->workoutSets()));
    $exposedExercise = $session->workoutExercises()[0];
    $exposedExercise->complete(new WorkoutSetCollection(...$exposedExercise->workoutSets()));

    expect($session->workoutExercises()[0]->status)->toBe(WorkoutExerciseStatus::Pending);
});

it('saves and resolves exercises in any order', function () use ($createSession, $singleSet) {
    $session = $createSession();

    $session->saveExerciseProgress(new ExerciseId(20), $singleSet(12, 36_000));
    $session->completeExercise(new ExerciseId(20), $singleSet(12, 36_000));
    $session->saveExerciseProgress(new ExerciseId(10), $singleSet(8, 100_000));
    $session->completeExercise(new ExerciseId(10), $singleSet(8, 100_000));

    $exercises = $session->workoutExercises();

    expect($exercises[0]->status)->toBe(WorkoutExerciseStatus::Completed);
    expect($exercises[0]->workoutSets()[0]->workingWeight->grams)->toBe(100_000);
    expect($exercises[1]->status)->toBe(WorkoutExerciseStatus::Completed);
});

it('skips and reopens an exercise while the session is active', function () use ($createSession) {
    $session = $createSession();
    $exerciseId = new ExerciseId(10);

    $session->skipExercise($exerciseId);
    $session->reopenExercise($exerciseId);

    expect($session->workoutExercises()[0]->status)->toBe(WorkoutExerciseStatus::Pending);
});

it('rejects completion while any exercise is pending', function () use ($createSession, $singleSet, $startedAt) {
    $session = $createSession();
    $session->completeExercise(new ExerciseId(10), $singleSet(8, 90_000));

    expect(fn () => $session->complete($startedAt->modify('+1 hour')))
        ->toThrow(WorkoutSessionHasPendingExercises::class);
});

it('completes after every exercise is completed or skipped', function () use ($createSession, $singleSet, $startedAt) {
    $session = $createSession();
    $completedAt = $startedAt->modify('+1 hour');
    $session->completeExercise(new ExerciseId(10), $singleSet(8, 90_000));
    $session->skipExercise(new ExerciseId(20));

    $session->complete($completedAt);

    expect($session->status)->toBe(WorkoutSessionStatus::Completed);
    expect($session->completedAt)->toEqual($completedAt);
    expect($session->cancelledAt)->toBeNull();
});

it('allows retrying completion without changing its original completion time', function () use ($createSession, $singleSet, $startedAt) {
    $session = $createSession();
    $completedAt = $startedAt->modify('+1 hour');
    $session->completeExercise(new ExerciseId(10), $singleSet(8, 90_000));
    $session->skipExercise(new ExerciseId(20));
    $session->complete($completedAt);

    $session->complete($startedAt->modify('+2 hours'));

    expect($session->status)->toBe(WorkoutSessionStatus::Completed);
    expect($session->completedAt)->toEqual($completedAt);
});

it('cancels an active session without resolving its exercises', function () use ($createSession, $startedAt) {
    $session = $createSession();
    $cancelledAt = $startedAt->modify('+10 minutes');

    $session->cancel($cancelledAt);

    expect($session->status)->toBe(WorkoutSessionStatus::Cancelled);
    expect($session->cancelledAt)->toEqual($cancelledAt);
    expect($session->completedAt)->toBeNull();
});

it('allows retrying cancellation without changing its original cancellation time', function () use ($createSession, $startedAt) {
    $session = $createSession();
    $cancelledAt = $startedAt->modify('+10 minutes');
    $session->cancel($cancelledAt);

    $session->cancel($startedAt->modify('+20 minutes'));

    expect($session->status)->toBe(WorkoutSessionStatus::Cancelled);
    expect($session->cancelledAt)->toEqual($cancelledAt);
});

it('rejects a terminal command that contradicts the existing resolution', function () use ($createSession, $singleSet, $startedAt) {
    $completed = $createSession();
    $completed->completeExercise(new ExerciseId(10), $singleSet(8, 90_000));
    $completed->skipExercise(new ExerciseId(20));
    $completed->complete($startedAt->modify('+1 hour'));

    $cancelled = $createSession();
    $cancelled->cancel($startedAt->modify('+10 minutes'));

    expect(fn () => $completed->cancel($startedAt->modify('+2 hours')))
        ->toThrow(WorkoutSessionIsNotInProgress::class);
    expect(fn () => $cancelled->complete($startedAt->modify('+2 hours')))
        ->toThrow(WorkoutSessionIsNotInProgress::class);
});

it('rejects a resolution time before the session started', function () use ($createSession, $startedAt) {
    $session = $createSession();

    expect(fn () => $session->cancel($startedAt->modify('-1 second')))
        ->toThrow(WorkoutResolutionBeforeStart::class);
});

it('rejects a completion time before the session started', function () use ($createSession, $singleSet, $startedAt) {
    $session = $createSession();
    $session->completeExercise(new ExerciseId(10), $singleSet(8, 90_000));
    $session->skipExercise(new ExerciseId(20));

    expect(fn () => $session->complete($startedAt->modify('-1 second')))
        ->toThrow(WorkoutResolutionBeforeStart::class);
});

it('makes a completed session immutable', function () use ($createSession, $singleSet, $startedAt) {
    $session = $createSession();
    $session->completeExercise(new ExerciseId(10), $singleSet(8, 90_000));
    $session->skipExercise(new ExerciseId(20));
    $session->complete($startedAt->modify('+1 hour'));

    expect(fn () => $session->saveExerciseProgress(new ExerciseId(10), $singleSet(5, 110_000)))
        ->toThrow(WorkoutSessionIsNotInProgress::class);
});

it('makes a cancelled session immutable', function () use ($createSession, $startedAt) {
    $session = $createSession();
    $session->cancel($startedAt->modify('+10 minutes'));

    expect(fn () => $session->reopenExercise(new ExerciseId(10)))
        ->toThrow(WorkoutSessionIsNotInProgress::class);
});

it('restores a persisted completed session', function () use ($workoutExercise, $startedAt) {
    $exercise = $workoutExercise(10, 1);
    $exercise->complete(new WorkoutSetCollection(...$exercise->workoutSets()));
    $completedAt = $startedAt->modify('+1 hour');

    $session = WorkoutSession::restore(
        new WorkoutSessionId(99),
        new UserId(42),
        new TrainingProgramSnapshot(
            new TrainingProgramId(7),
            new ProgramName('Понедельник — грудь'),
            ScheduledWeekday::Monday,
        ),
        new WorkoutExerciseCollection($exercise),
        WorkoutSessionStatus::Completed,
        $startedAt,
        $completedAt,
        null,
    );

    expect($session->id?->value)->toBe(99);
    expect($session->status)->toBe(WorkoutSessionStatus::Completed);
    expect($session->completedAt)->toEqual($completedAt);
});

it('rejects restoring a completed session with pending exercises', function () use ($workoutExercise, $startedAt) {
    expect(fn () => WorkoutSession::restore(
        new WorkoutSessionId(99),
        new UserId(42),
        new TrainingProgramSnapshot(
            new TrainingProgramId(7),
            new ProgramName('Понедельник — грудь'),
            ScheduledWeekday::Monday,
        ),
        new WorkoutExerciseCollection($workoutExercise(10, 1)),
        WorkoutSessionStatus::Completed,
        $startedAt,
        $startedAt->modify('+1 hour'),
        null,
    ))->toThrow(InvalidWorkoutSessionState::class);
});

it('rejects restoring a session with timestamps that contradict its status', function (
    WorkoutSessionStatus $status,
    ?DateTimeImmutable $completedAt,
    ?DateTimeImmutable $cancelledAt,
) use ($workoutExercise, $startedAt) {
    $exercise = $workoutExercise(10, 1);

    if ($status === WorkoutSessionStatus::Completed) {
        $exercise->complete(new WorkoutSetCollection(...$exercise->workoutSets()));
    }

    expect(fn () => WorkoutSession::restore(
        new WorkoutSessionId(99),
        new UserId(42),
        new TrainingProgramSnapshot(
            new TrainingProgramId(7),
            new ProgramName('Понедельник — грудь'),
            ScheduledWeekday::Monday,
        ),
        new WorkoutExerciseCollection($exercise),
        $status,
        $startedAt,
        $completedAt,
        $cancelledAt,
    ))->toThrow(InvalidWorkoutSessionState::class);
})->with([
    'active with completion time' => [
        WorkoutSessionStatus::InProgress,
        $startedAt->modify('+1 hour'),
        null,
    ],
    'active with cancellation time' => [
        WorkoutSessionStatus::InProgress,
        null,
        $startedAt->modify('+1 hour'),
    ],
    'completed without completion time' => [
        WorkoutSessionStatus::Completed,
        null,
        null,
    ],
    'completed before start' => [
        WorkoutSessionStatus::Completed,
        $startedAt->modify('-1 second'),
        null,
    ],
    'completed with cancellation time' => [
        WorkoutSessionStatus::Completed,
        $startedAt->modify('+1 hour'),
        $startedAt->modify('+30 minutes'),
    ],
    'cancelled without cancellation time' => [
        WorkoutSessionStatus::Cancelled,
        null,
        null,
    ],
    'cancelled before start' => [
        WorkoutSessionStatus::Cancelled,
        null,
        $startedAt->modify('-1 second'),
    ],
    'cancelled with completion time' => [
        WorkoutSessionStatus::Cancelled,
        $startedAt->modify('+1 hour'),
        $startedAt->modify('+30 minutes'),
    ],
]);
