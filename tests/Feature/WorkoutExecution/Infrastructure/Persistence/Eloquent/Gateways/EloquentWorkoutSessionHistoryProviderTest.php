<?php

use App\Models\User;
use App\WorkoutExecution\Domain\Collections\WorkoutExerciseCollection;
use App\WorkoutExecution\Domain\Entities\WorkoutExercise;
use App\WorkoutExecution\Domain\Entities\WorkoutSession;
use App\WorkoutExecution\Domain\Enums\ScheduledWeekday;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseName;
use App\WorkoutExecution\Domain\ValueObjects\ExercisePosition;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\ProgramName;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutExerciseReplacement;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Gateways\EloquentWorkoutSessionHistoryProvider;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Repositories\EloquentWorkoutSessionRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\WorkoutExecution\WorkoutSessionFixture;

uses(LazilyRefreshDatabase::class);

it('eager loads replacement history for terminal sessions using a fixed number of queries', function (): void {
    $user = User::factory()->create();
    $repository = app(EloquentWorkoutSessionRepository::class);
    $ids = [];
    foreach ([1, 2] as $day) {
        $session = $repository->add(WorkoutSession::start(
            new UserId($user->id),
            new TrainingProgramSnapshot(new TrainingProgramId(71), new ProgramName('Тренировка'), ScheduledWeekday::Monday),
            new WorkoutExerciseCollection(WorkoutExercise::fromPlan(
                new ExerciseSnapshot(new ExerciseId(501), new ExerciseName('Жим лежа'), new ExercisePosition(1)),
                WorkoutSessionFixture::sets(3, 8, 90_000),
                0,
            )),
            new DateTimeImmutable("2026-09-{$day} 16:00:00 UTC"),
        ));
        $session->replaceExercise(new ExerciseId(501), new WorkoutExerciseReplacement(
            1,
            '550e8400-e29b-41d4-a716-446655440000',
            new ExerciseSnapshot(new ExerciseId(601), new ExerciseName('Новое упражнение'), new ExercisePosition(1)),
            WorkoutSessionFixture::sets(2, 10, 35_000),
            false,
            new DateTimeImmutable("2026-09-{$day} 16:15:00 UTC"),
        ));
        $session->saveExerciseProgress(new ExerciseId(501), WorkoutSessionFixture::sets(1, 7, 37_000));
        $session->cancel(new DateTimeImmutable("2026-09-{$day} 17:00:00 UTC"));
        $repository->save($session);
        $ids[] = $session->id?->value;
    }
    $connection = app(DatabaseManager::class)->connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        $history = app(EloquentWorkoutSessionHistoryProvider::class)->paginateForUser(new UserId($user->id), 15, null);
        $queries = $connection->getQueryLog();
    } finally {
        $connection->disableQueryLog();
    }

    expect(array_map(static fn ($session): int => $session->id, $history->sessions))->toBe(array_reverse($ids));
    expect($history->sessions[0]->exercises[0]->exerciseId)->toBe(501);
    expect($history->sessions[0]->exercises[0]->sets[0]->workingWeightInGrams)->toBe(37_000);
    expect($history->sessions[0]->exercises[0]->plannedSets[0]->workingWeightInGrams)->toBe(90_000);
    expect($queries)->toHaveCount(6);
});
