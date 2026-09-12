<?php

use App\Models\Exercise;
use App\Models\User;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Infrastructure\Integrations\WorkoutPlanning\EloquentTrainingProgramSnapshotProvider;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\TrainingProgramModel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

$provider = fn (): EloquentTrainingProgramSnapshotProvider => new EloquentTrainingProgramSnapshotProvider(
    app(DatabaseManager::class),
);

it('returns an ordered immutable snapshot of an owned training program', function () use ($provider): void {
    $user = User::factory()->create();
    $benchPress = Exercise::factory()->create(['name' => 'Жим лежа']);
    $triceps = Exercise::factory()->create(['name' => 'Разгибание на трицепс']);
    $program = TrainingProgramModel::query()->create([
        'user_id' => $user->id,
        'weekday' => 1,
        'name' => 'Грудь и трицепс',
    ]);
    $program->plannedExercises()->createMany([
        [
            'exercise_id' => $triceps->id,
            'sets' => 3,
            'repetitions_per_set' => 12,
            'working_weight_grams' => 36_000,
            'position' => 2,
        ],
        [
            'exercise_id' => $benchPress->id,
            'sets' => 3,
            'repetitions_per_set' => 8,
            'working_weight_grams' => 90_000,
            'position' => 1,
        ],
    ]);

    $snapshot = $provider()->findForUser(
        new TrainingProgramId($program->id),
        new UserId($user->id),
    );

    if ($snapshot === null) {
        throw new LogicException('Снимок существующей программы должен быть найден.');
    }

    expect([
        $snapshot->trainingProgramId,
        $snapshot->name,
        $snapshot->scheduledWeekday,
    ])->toBe([$program->id, 'Грудь и трицепс', 1]);
    expect(array_map(
        static fn ($exercise): array => [
            $exercise->exerciseId,
            $exercise->name,
            $exercise->sets,
            $exercise->repetitionsPerSet,
            $exercise->workingWeightInGrams,
            $exercise->position,
        ],
        $snapshot->exercises,
    ))->toBe([
        [$benchPress->id, 'Жим лежа', 3, 8, 90_000, 1],
        [$triceps->id, 'Разгибание на трицепс', 3, 12, 36_000, 2],
    ]);
});

it('does not expose a training program to another user', function () use ($provider): void {
    $owner = User::factory()->create();
    $anotherUser = User::factory()->create();
    $program = TrainingProgramModel::query()->create([
        'user_id' => $owner->id,
        'weekday' => 1,
        'name' => 'Тренировка',
    ]);

    expect($provider()->findForUser(
        new TrainingProgramId($program->id),
        new UserId($anotherUser->id),
    ))->toBeNull();
});
