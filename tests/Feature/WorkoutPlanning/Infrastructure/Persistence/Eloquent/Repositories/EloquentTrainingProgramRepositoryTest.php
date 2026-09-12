<?php

use App\Models\Exercise;
use App\Models\User;
use App\WorkoutPlanning\Domain\Collections\PlannedExerciseCollection;
use App\WorkoutPlanning\Domain\Collections\PlannedSetCollection;
use App\WorkoutPlanning\Domain\Entities\PlannedExercise;
use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\Exceptions\TrainingProgramAlreadyExists;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use App\WorkoutPlanning\Domain\ValueObjects\ExercisePosition;
use App\WorkoutPlanning\Domain\ValueObjects\PlannedSet;
use App\WorkoutPlanning\Domain\ValueObjects\ProgramName;
use App\WorkoutPlanning\Domain\ValueObjects\Repetitions;
use App\WorkoutPlanning\Domain\ValueObjects\SetPosition;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use App\WorkoutPlanning\Domain\ValueObjects\WorkingWeight;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Mappers\TrainingProgramMapper;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Repositories\EloquentTrainingProgramRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

$repository = fn (): EloquentTrainingProgramRepository => new EloquentTrainingProgramRepository(
    new TrainingProgramMapper,
    app(DatabaseManager::class),
);

$plannedExercise = static fn (
    Exercise $exercise,
    int $sets,
    int $repetitions,
    int $weightInGrams,
    int $position,
): PlannedExercise => new PlannedExercise(
    new ExerciseId($exercise->id),
    new PlannedSetCollection(...array_map(
        static fn (int $position): PlannedSet => new PlannedSet(
            new SetPosition($position),
            new Repetitions($repetitions),
            new WorkingWeight($weightInGrams),
        ),
        range(1, $sets),
    )),
    new ExercisePosition($position),
);

it('persists and rehydrates the complete aggregate', function () use ($repository, $plannedExercise): void {
    $user = User::factory()->create();
    $benchPress = Exercise::factory()->create();
    $squat = Exercise::factory()->create();
    $trainingProgram = TrainingProgram::create(
        new UserId($user->id),
        Weekday::Monday,
        new PlannedExerciseCollection(
            $plannedExercise($benchPress, 3, 6, 100_000, 1),
            $plannedExercise($squat, 4, 8, 120_000, 2),
        ),
        new ProgramName('Силовая тренировка'),
    );

    $persistedProgram = $repository()->add($trainingProgram);
    $persistedProgramId = $persistedProgram->id
        ?? throw new LogicException('Сохранённая программа должна иметь идентификатор.');

    $this->assertDatabaseHas('training_programs', [
        'id' => $persistedProgramId->value,
        'user_id' => $user->id,
        'weekday' => Weekday::Monday->value,
        'name' => 'Силовая тренировка',
    ]);
    $this->assertDatabaseHas('planned_exercises', [
        'training_program_id' => $persistedProgramId->value,
        'exercise_id' => $benchPress->id,
        'position' => 1,
    ]);
    $this->assertDatabaseCount('planned_sets', 7);
    $this->assertDatabaseHas('planned_sets', [
        'position' => 3,
        'repetitions' => 6,
        'working_weight_grams' => 100_000,
    ]);

    $rehydratedProgram = $repository()->findForUserOnWeekday(
        new UserId($user->id),
        Weekday::Monday,
    );

    expect($rehydratedProgram?->name->value)->toBe('Силовая тренировка');
    expect(array_map(
        static fn (PlannedExercise $exercise): array => [
            $exercise->exerciseId->value,
            array_map(static fn (PlannedSet $set): array => [
                $set->position->value,
                $set->repetitions->value,
                $set->workingWeight->grams,
            ], $exercise->plannedSets()),
            $exercise->position->value,
        ],
        $rehydratedProgram?->plannedExercises() ?? [],
    ))->toBe([
        [$benchPress->id, [[1, 6, 100_000], [2, 6, 100_000], [3, 6, 100_000]], 1],
        [$squat->id, [[1, 8, 120_000], [2, 8, 120_000], [3, 8, 120_000], [4, 8, 120_000]], 2],
    ]);
});

it('rejects a second active program for the same user and weekday atomically', function () use ($repository, $plannedExercise): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $firstProgram = TrainingProgram::create(
        new UserId($user->id),
        Weekday::Wednesday,
        new PlannedExerciseCollection($plannedExercise($exercise, 3, 6, 100_000, 1)),
    );
    $secondProgram = TrainingProgram::create(
        new UserId($user->id),
        Weekday::Wednesday,
        new PlannedExerciseCollection($plannedExercise($exercise, 5, 5, 110_000, 1)),
    );
    $trainingPrograms = $repository();
    $trainingPrograms->add($firstProgram);

    expect(fn () => $trainingPrograms->add($secondProgram))
        ->toThrow(TrainingProgramAlreadyExists::class);
    $this->assertDatabaseCount('training_programs', 1);
    $this->assertDatabaseCount('planned_exercises', 1);
});

it('replaces the persisted exercise prescription in one save', function () use ($repository, $plannedExercise): void {
    $user = User::factory()->create();
    $benchPress = Exercise::factory()->create();
    $squat = Exercise::factory()->create();
    $trainingPrograms = $repository();
    $trainingProgram = $trainingPrograms->add(TrainingProgram::create(
        new UserId($user->id),
        Weekday::Friday,
        new PlannedExerciseCollection($plannedExercise($benchPress, 3, 6, 100_000, 1)),
    ));
    $trainingProgram->rename(new ProgramName('Обновлённая тренировка'));
    $trainingProgram->replaceExercises(new PlannedExerciseCollection(
        $plannedExercise($squat, 4, 8, 120_000, 1),
        $plannedExercise($benchPress, 5, 5, 110_000, 2),
    ));
    $trainingProgramId = $trainingProgram->id
        ?? throw new LogicException('Сохранённая программа должна иметь идентификатор.');

    $trainingPrograms->save($trainingProgram);

    $this->assertDatabaseCount('planned_exercises', 2);
    $this->assertDatabaseHas('training_programs', [
        'id' => $trainingProgramId->value,
        'name' => 'Обновлённая тренировка',
    ]);
    $this->assertDatabaseHas('planned_exercises', [
        'training_program_id' => $trainingProgramId->value,
        'exercise_id' => $squat->id,
        'position' => 1,
    ]);
    $this->assertDatabaseHas('planned_exercises', [
        'training_program_id' => $trainingProgramId->value,
        'exercise_id' => $benchPress->id,
        'position' => 2,
    ]);
    $this->assertDatabaseCount('planned_sets', 9);
    $this->assertDatabaseHas('planned_sets', [
        'position' => 5,
        'repetitions' => 5,
        'working_weight_grams' => 110_000,
    ]);
});

it('does not return a program through another user', function () use ($repository, $plannedExercise): void {
    $owner = User::factory()->create();
    $anotherUser = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $trainingPrograms = $repository();
    $trainingProgram = $trainingPrograms->add(TrainingProgram::create(
        new UserId($owner->id),
        Weekday::Sunday,
        new PlannedExerciseCollection($plannedExercise($exercise, 3, 6, 100_000, 1)),
    ));
    $trainingProgramId = $trainingProgram->id
        ?? throw new LogicException('Сохранённая программа должна иметь идентификатор.');

    $result = $trainingPrograms->findForUser(
        $trainingProgramId,
        new UserId($anotherUser->id),
    );

    expect($result)->toBeNull();
});

it('hard deletes the aggregate and all planned exercises', function () use ($repository, $plannedExercise): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $trainingPrograms = $repository();
    $trainingProgram = $trainingPrograms->add(TrainingProgram::create(
        new UserId($user->id),
        Weekday::Thursday,
        new PlannedExerciseCollection($plannedExercise($exercise, 3, 6, 100_000, 1)),
    ));
    $trainingProgramId = $trainingProgram->id
        ?? throw new LogicException('Сохранённая программа должна иметь идентификатор.');

    $trainingPrograms->delete($trainingProgram);

    $this->assertDatabaseMissing('training_programs', ['id' => $trainingProgramId->value]);
    $this->assertDatabaseMissing('planned_exercises', ['training_program_id' => $trainingProgramId->value]);
    $this->assertDatabaseCount('planned_sets', 0);
});
