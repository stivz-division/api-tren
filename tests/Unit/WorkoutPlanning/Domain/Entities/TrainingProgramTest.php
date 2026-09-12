<?php

use App\WorkoutPlanning\Domain\Collections\PlannedExerciseCollection;
use App\WorkoutPlanning\Domain\Entities\PlannedExercise;
use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\Exceptions\ExerciseAlreadyPlanned;
use App\WorkoutPlanning\Domain\Exceptions\InvalidExerciseOrder;
use App\WorkoutPlanning\Domain\Exceptions\PlannedExerciseNotFound;
use App\WorkoutPlanning\Domain\Exceptions\TrainingProgramMustContainExercise;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use App\WorkoutPlanning\Domain\ValueObjects\ExercisePosition;
use App\WorkoutPlanning\Domain\ValueObjects\ProgramName;
use App\WorkoutPlanning\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use Tests\Support\WorkoutPlanning\PlannedExerciseFixture;

$createProgram = static function (?ProgramName $name = null): TrainingProgram {
    $exercise = PlannedExerciseFixture::exercise();

    return TrainingProgram::create(
        new UserId(111_111_111),
        Weekday::Monday,
        new PlannedExerciseCollection($exercise),
        $name,
    );
};

it('creates a new program without a persistence identity', function () use ($createProgram) {
    $program = $createProgram();

    expect($program->id)->toBeNull();
    expect($program->userId->value)->toBe(111_111_111);
    expect($program->weekday)->toBe(Weekday::Monday);
    expect($program->name->value)->toBe('Тренировка');
    expect($program->plannedExercises())->toHaveCount(1);
});

it('restores a persisted program with its identity', function () {
    $program = TrainingProgram::restore(
        new TrainingProgramId(1),
        new UserId(111_111_111),
        Weekday::Monday,
        new PlannedExerciseCollection(PlannedExerciseFixture::exercise()),
        ProgramName::default(),
    );

    expect($program->id?->value)->toBe(1);
});

it('protects its exercises from mutations through external references', function () {
    $exercise = PlannedExerciseFixture::exercise();
    $externalCollection = new PlannedExerciseCollection($exercise);
    $program = TrainingProgram::create(
        new UserId(111_111_111),
        Weekday::Monday,
        $externalCollection,
    );

    $exercise->replaceSets(PlannedExerciseFixture::sets(5, 5, 110_000));
    $externalCollection->add(PlannedExerciseFixture::exercise(20, 2, 4, 8, 50_000));
    $program->plannedExercises()[0]->moveTo(new ExercisePosition(2));

    expect($program->plannedExercises())->toHaveCount(1);
    expect($program->plannedExercises()[0]->plannedSets())->toHaveCount(3);
    expect($program->plannedExercises()[0]->position->value)->toBe(1);
});

it('creates a program with a custom name', function () use ($createProgram) {
    expect($createProgram(new ProgramName('Грудь'))->name->value)->toBe('Грудь');
});

it('renames a program', function () use ($createProgram) {
    $program = $createProgram();

    $program->rename(new ProgramName('Силовая тренировка'));

    expect($program->name->value)->toBe('Силовая тренировка');
});

it('replaces all planned exercises without keeping external references', function () use ($createProgram) {
    $program = $createProgram();
    $replacement = new PlannedExerciseCollection(PlannedExerciseFixture::exercise(20, 1, 4, 8, 50_000));

    $program->replaceExercises($replacement);
    $replacement->get(new ExerciseId(20))->replaceSets(PlannedExerciseFixture::sets(5, 5, 60_000));

    expect($program->plannedExercises())->toHaveCount(1);
    expect($program->plannedExercises()[0]->exerciseId->value)->toBe(20);
    expect($program->plannedExercises()[0]->plannedSets())->toHaveCount(4);
});

it('adds an exercise once and appends it to the program', function () use ($createProgram) {
    $program = $createProgram();

    $program->addExercise(
        new ExerciseId(20),
        PlannedExerciseFixture::sets(4, 8, 50_000),
    );

    expect($program->plannedExercises())->toHaveCount(2);
    expect($program->plannedExercises()[1]->exerciseId->value)->toBe(20);
    expect($program->plannedExercises()[1]->position->value)->toBe(2);
});

it('rejects adding the same exercise twice', function () use ($createProgram) {
    $program = $createProgram();

    expect(fn () => $program->addExercise(
        new ExerciseId(10),
        PlannedExerciseFixture::sets(4, 8, 90_000),
    ))->toThrow(ExerciseAlreadyPlanned::class);
});

it('replaces an exercises planned sets', function () use ($createProgram) {
    $program = $createProgram();

    $program->replaceExerciseSets(
        new ExerciseId(10),
        PlannedExerciseFixture::sets(5, 5, 110_000),
    );

    $exercise = $program->plannedExercises()[0];

    expect($exercise->plannedSets())->toHaveCount(5)
        ->and($exercise->plannedSets()[4]->repetitions->value)->toBe(5)
        ->and($exercise->plannedSets()[4]->workingWeight->grams)->toBe(110_000);
});

it('does not remove the last exercise', function () use ($createProgram) {
    $program = $createProgram();

    expect(fn () => $program->removeExercise(new ExerciseId(10)))
        ->toThrow(TrainingProgramMustContainExercise::class);
});

it('removes an exercise and closes the position gap', function () use ($createProgram) {
    $program = $createProgram();
    $program->addExercise(
        new ExerciseId(20),
        PlannedExerciseFixture::sets(4, 8, 50_000),
    );
    $program->addExercise(
        new ExerciseId(30),
        PlannedExerciseFixture::sets(2, 10, 30_000),
    );

    $program->removeExercise(new ExerciseId(20));

    expect(array_map(
        static fn (PlannedExercise $exercise): array => [
            $exercise->exerciseId->value,
            $exercise->position->value,
        ],
        $program->plannedExercises(),
    ))->toBe([[10, 1], [30, 2]]);
});

it('rejects changing an exercise that is not planned', function () use ($createProgram) {
    $program = $createProgram();

    expect(fn () => $program->replaceExerciseSets(
        new ExerciseId(20),
        PlannedExerciseFixture::sets(4, 8, 50_000),
    ))->toThrow(PlannedExerciseNotFound::class);
});

it('rejects removing an exercise that is not planned', function () use ($createProgram) {
    $program = $createProgram();

    expect(fn () => $program->removeExercise(new ExerciseId(20)))
        ->toThrow(PlannedExerciseNotFound::class);
});

it('reorders its planned exercises', function () use ($createProgram) {
    $program = $createProgram();
    $program->addExercise(
        new ExerciseId(20),
        PlannedExerciseFixture::sets(4, 8, 50_000),
    );

    $program->reorderExercises(new ExerciseId(20), new ExerciseId(10));

    expect(array_map(
        static fn (PlannedExercise $exercise): int => $exercise->exerciseId->value,
        $program->plannedExercises(),
    ))->toBe([20, 10]);
});

it('rejects an incomplete exercise order', function () use ($createProgram) {
    $program = $createProgram();
    $program->addExercise(
        new ExerciseId(20),
        PlannedExerciseFixture::sets(4, 8, 50_000),
    );

    expect(fn () => $program->reorderExercises(new ExerciseId(10)))
        ->toThrow(InvalidExerciseOrder::class);
});
