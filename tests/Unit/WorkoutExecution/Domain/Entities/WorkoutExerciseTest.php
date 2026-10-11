<?php

use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\Entities\WorkoutExercise;
use App\WorkoutExecution\Domain\Enums\WorkoutExerciseStatus;
use App\WorkoutExecution\Domain\Exceptions\InvalidWorkoutExerciseReplacement;
use App\WorkoutExecution\Domain\Exceptions\InvalidWorkoutExerciseState;
use App\WorkoutExecution\Domain\Exceptions\WorkoutExerciseHasNoSets;
use App\WorkoutExecution\Domain\Exceptions\WorkoutExerciseIsNotEditable;
use App\WorkoutExecution\Domain\Exceptions\WorkoutReplacementRequestConflict;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseName;
use App\WorkoutExecution\Domain\ValueObjects\ExercisePosition;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\Repetitions;
use App\WorkoutExecution\Domain\ValueObjects\SetPosition;
use App\WorkoutExecution\Domain\ValueObjects\WorkingWeight;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutExerciseReplacement;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSet;
use Tests\Support\WorkoutExecution\WorkoutSessionFixture;

$createExercise = static fn (): WorkoutExercise => WorkoutExercise::fromPlan(
    new ExerciseSnapshot(
        new ExerciseId(10),
        new ExerciseName('Жим лежа'),
        new ExercisePosition(1),
    ),
    WorkoutSessionFixture::sets(),
);

$changedSets = static fn (): WorkoutSetCollection => new WorkoutSetCollection(
    new WorkoutSet(new SetPosition(1), new Repetitions(8), new WorkingWeight(90_000)),
    new WorkoutSet(new SetPosition(2), new Repetitions(8), new WorkingWeight(100_000)),
    new WorkoutSet(new SetPosition(3), new Repetitions(7), new WorkingWeight(100_000)),
    new WorkoutSet(new SetPosition(4), new Repetitions(6), new WorkingWeight(95_000)),
);

$replacement = static fn (
    int $sequence = 1,
    int $exerciseId = 30,
    bool $replaceInPlan = false,
    ?int $revision = null,
    int $weight = 25_000,
): WorkoutExerciseReplacement => new WorkoutExerciseReplacement(
    $sequence,
    sprintf('550e8400-e29b-41d4-a716-%012d', $sequence),
    new ExerciseSnapshot(new ExerciseId($exerciseId), new ExerciseName('Новое упражнение'), new ExercisePosition(1)),
    new WorkoutSetCollection(new WorkoutSet(new SetPosition(1), new Repetitions(10), new WorkingWeight($weight))),
    $replaceInPlan,
    new DateTimeImmutable('2026-10-09 12:00:00 UTC'),
    $revision,
);

it('keeps the original snapshot and initializes a pending replacement draft', function (bool $replaceInPlan) use ($createExercise, $changedSets, $replacement) {
    $exercise = $createExercise();
    $exercise->saveProgress($changedSets());
    $original = $exercise->snapshot;

    $exercise->replace($replacement(replaceInPlan: $replaceInPlan));

    expect($exercise->snapshot)->toBe($original);
    expect($exercise->plannedSets())->toHaveCount(3);
    expect($exercise->effectiveSnapshot()->exerciseId->value)->toBe(30);
    expect($exercise->effectivePlannedSets()[0]->workingWeight->grams)->toBe(25_000);
    expect($exercise->workoutSets())->toHaveCount(1);
    expect($exercise->status)->toBe(WorkoutExerciseStatus::Pending);
    expect($exercise->includedInAnalysis())->toBe($replaceInPlan);
})->with([false, true]);

it('uses the last permanent replacement as the plan baseline', function () use ($createExercise, $replacement) {
    $exercise = $createExercise();
    $exercise->replace($replacement(1, 30, true, 4));
    $exercise->replace($replacement(2, 40, false));

    expect($exercise->effectiveSnapshot()->exerciseId->value)->toBe(40);
    expect($exercise->planBaselineSnapshot()->exerciseId->value)->toBe(30);
    expect($exercise->planBaselineSets()[0]->workingWeight->grams)->toBe(25_000);
    expect($exercise->planBaselineRevision())->toBe(4);
    expect($exercise->includedInAnalysis())->toBeFalse();
});

it('keeps the original plan baseline through temporary replacements and permits returning to it', function () use ($createExercise, $replacement) {
    $exercise = $createExercise();
    $exercise->replace($replacement(1, 30, weight: 20_000));
    $exercise->replace($replacement(2, 10));

    expect($exercise->effectiveSnapshot()->exerciseId->value)->toBe(10);
    expect($exercise->planBaselineSnapshot()->exerciseId->value)->toBe(10);
    expect($exercise->planBaselineSets())->toHaveCount(3);
    expect($exercise->replacements())->toHaveCount(2);
});

it('restores the effective replacement load after skip and reopen', function () use ($createExercise, $replacement) {
    $exercise = $createExercise();
    $exercise->replace($replacement());
    $exercise->skip();

    $exercise->reopen();

    expect($exercise->workoutSets())->toHaveCount(1);
    expect($exercise->workoutSets()[0]->workingWeight->grams)->toBe(25_000);
});

it('replays an earlier request without overwriting a later replacement or completed progress', function () use ($createExercise, $replacement, $changedSets) {
    $exercise = $createExercise();
    $first = $replacement();
    $exercise->replace($first);
    $exercise->replace($replacement(2, 40, true, 5));
    $exercise->complete($changedSets());

    expect($exercise->replace($first))->toBeFalse();
    expect($exercise->effectiveSnapshot()->exerciseId->value)->toBe(40);
    expect($exercise->workoutSets())->toHaveCount(4);
    expect($exercise->status)->toBe(WorkoutExerciseStatus::Completed);
});

it('matches replay by client payload regardless of the catalogue name', function () use ($createExercise, $replacement) {
    $exercise = $createExercise();
    $first = $replacement();
    $exercise->replace($first);
    $renamed = new WorkoutExerciseReplacement(
        1, strtoupper($first->requestId),
        new ExerciseSnapshot(new ExerciseId(30), new ExerciseName('Переименовано'), new ExercisePosition(1)),
        new WorkoutSetCollection(...$first->plannedSets()), false,
        new DateTimeImmutable('2026-10-10 UTC'),
    );

    expect($exercise->replace($renamed))->toBeFalse();
    expect($exercise->effectiveSnapshot()->name->value)->toBe('Новое упражнение');
});

it('rejects reusing a request UUID for different client payload', function (string $difference) use ($createExercise, $replacement, $changedSets) {
    $exercise = $createExercise();
    $first = $replacement();
    $exercise->replace($first);

    expect(fn () => $exercise->isReplacementReplay(
        $first->requestId,
        new ExerciseId($difference === 'exercise' ? 40 : 30),
        $difference === 'mode',
        $difference === 'sets' ? $changedSets() : new WorkoutSetCollection(...$first->plannedSets()),
    ))->toThrow(WorkoutReplacementRequestConflict::class);
    expect($exercise->replacements())->toHaveCount(1);
})->with(['exercise', 'mode', 'sets']);

it('rejects a new replacement until a resolved exercise is reopened', function (bool $skip) use ($createExercise, $replacement) {
    $exercise = $createExercise();
    if ($skip) {
        $exercise->skip();
    } else {
        $exercise->complete(new WorkoutSetCollection(...$exercise->workoutSets()));
    }

    expect(fn () => $exercise->replace($replacement()))->toThrow(WorkoutExerciseIsNotEditable::class);
    expect($exercise->replacements())->toBe([]);
})->with([false, true]);

it('rejects replacement records with a different position or a sequence gap', function (int $sequence, int $position) use ($createExercise, $replacement) {
    $exercise = $createExercise();
    $first = $replacement();
    $invalid = new WorkoutExerciseReplacement(
        $sequence, $first->requestId,
        new ExerciseSnapshot(new ExerciseId(30), new ExerciseName('Новое'), new ExercisePosition($position)),
        new WorkoutSetCollection(...$first->plannedSets()), false, $first->createdAt,
    );

    expect(fn () => $exercise->replace($invalid))->toThrow(InvalidWorkoutExerciseReplacement::class);
    expect($exercise->effectiveSnapshot()->exerciseId->value)->toBe(10);
})->with(['sequence gap' => [2, 1], 'different position' => [1, 2]]);

it('rejects replacing the current effective exercise by itself', function () use ($createExercise, $replacement) {
    $exercise = $createExercise();

    expect(fn () => $exercise->replace($replacement(exerciseId: 10)))
        ->toThrow(InvalidWorkoutExerciseReplacement::class);
});

it('rejects a replacement without planned sets', function () use ($replacement) {
    $first = $replacement();

    expect(fn () => new WorkoutExerciseReplacement(
        1, $first->requestId, $first->snapshot, new WorkoutSetCollection, false, $first->createdAt,
    ))->toThrow(WorkoutExerciseHasNoSets::class);
});

it('restores replacement history and its immutable source revision', function () use ($createExercise, $replacement) {
    $original = $createExercise();
    $record = $replacement(1, 30, true, 8);

    $exercise = WorkoutExercise::restore(
        $original->snapshot, new WorkoutSetCollection(...$original->plannedSets()),
        new WorkoutSetCollection(...$record->plannedSets()), WorkoutExerciseStatus::Pending,
        [$record], 6,
    );

    expect($exercise->sourcePlanRevision)->toBe(6);
    expect($exercise->planBaselineRevision())->toBe(8);
    expect($exercise->latestReplacement())->toBe($record);
});

it('advances the permanent baseline across mixed replacement chains', function () use ($createExercise, $replacement) {
    $exercise = $createExercise();
    $exercise->replace($replacement(1, 30));

    expect($exercise->planBaselineSnapshot()->exerciseId->value)->toBe(10);
    expect($exercise->planBaselineSets())->toHaveCount(3);

    $exercise->replace($replacement(2, 40, true, 7, 35_000));

    expect($exercise->planBaselineSnapshot()->exerciseId->value)->toBe(40);
    expect($exercise->planBaselineSets()[0]->workingWeight->grams)->toBe(35_000);
    expect($exercise->planBaselineRevision())->toBe(7);

    $exercise->replace($replacement(3, 50, weight: 15_000));

    expect($exercise->planBaselineSnapshot()->exerciseId->value)->toBe(40);
    expect($exercise->planBaselineRevision())->toBe(7);
    expect($exercise->planBaselineSets()[0]->workingWeight->grams)->toBe(35_000);
    expect($exercise->effectivePlannedSets()[0]->workingWeight->grams)->toBe(15_000);

    $exercise->replace($replacement(4, 60, true, 9, 50_000));

    expect($exercise->effectiveSnapshot()->exerciseId->value)->toBe(60);
    expect($exercise->planBaselineSnapshot()->exerciseId->value)->toBe(60);
    expect($exercise->planBaselineRevision())->toBe(9);
    expect($exercise->planBaselineSets()[0]->workingWeight->grams)->toBe(50_000);
    expect($exercise->includedInAnalysis())->toBeTrue();
    expect($exercise->snapshot->exerciseId->value)->toBe(10);
    expect($exercise->plannedSets())->toHaveCount(3);
});

it('does not infer a missing permanent revision from the source revision', function () use ($createExercise, $replacement) {
    $original = $createExercise();
    $exercise = WorkoutExercise::fromPlan($original->snapshot, new WorkoutSetCollection(...$original->plannedSets()), 6);

    $exercise->replace($replacement(1, 30, true));

    expect($exercise->planBaselineRevision())->toBeNull();
    expect($exercise->sourcePlanRevision)->toBe(6);
});

it('rejects restoring invalid replacement histories', function (string $invalidity) use ($createExercise, $replacement) {
    $original = $createExercise();
    $first = $replacement();
    $next = $replacement(2, 40);
    $history = match ($invalidity) {
        'sequence gap' => [$next],
        'unchanged exercise' => [$replacement(1, 10)],
        'repeated effective exercise' => [$first, $replacement(2, 30)],
        'duplicate request' => [$first, new WorkoutExerciseReplacement(
            2, strtoupper($first->requestId), $next->snapshot,
            new WorkoutSetCollection(...$next->plannedSets()), false, $next->createdAt,
        )],
        'different position' => [new WorkoutExerciseReplacement(
            1, $first->requestId,
            new ExerciseSnapshot(new ExerciseId(30), new ExerciseName('Новое'), new ExercisePosition(2)),
            new WorkoutSetCollection(...$first->plannedSets()), false, $first->createdAt,
        )],
        default => throw new InvalidArgumentException('Unknown invalid replacement history case.'),
    };

    expect(fn () => WorkoutExercise::restore(
        $original->snapshot, new WorkoutSetCollection(...$original->plannedSets()),
        new WorkoutSetCollection(...$first->plannedSets()), WorkoutExerciseStatus::Pending, $history,
    ))->toThrow(InvalidWorkoutExerciseReplacement::class);
})->with(['sequence gap', 'unchanged exercise', 'repeated effective exercise', 'duplicate request', 'different position']);

it('matches replay sets after normalizing their positions and detects changed order', function () use ($createExercise) {
    $exercise = $createExercise();
    $firstSet = new WorkoutSet(new SetPosition(1), new Repetitions(10), new WorkingWeight(25_000));
    $secondSet = new WorkoutSet(new SetPosition(2), new Repetitions(8), new WorkingWeight(30_000));
    $record = new WorkoutExerciseReplacement(
        1, '550e8400-e29b-41d4-a716-000000000001',
        new ExerciseSnapshot(new ExerciseId(30), new ExerciseName('Новое'), new ExercisePosition(1)),
        new WorkoutSetCollection($secondSet, $firstSet), false, new DateTimeImmutable('2026-10-09 UTC'),
    );
    $exercise->replace($record);

    expect($exercise->isReplacementReplay($record->requestId, new ExerciseId(30), false, new WorkoutSetCollection($firstSet, $secondSet)))->toBeTrue();
    expect(fn () => $exercise->isReplacementReplay(
        $record->requestId, new ExerciseId(30), false,
        new WorkoutSetCollection(
            new WorkoutSet(new SetPosition(1), new Repetitions(8), new WorkingWeight(30_000)),
            new WorkoutSet(new SetPosition(2), new Repetitions(10), new WorkingWeight(25_000)),
        ),
    ))->toThrow(WorkoutReplacementRequestConflict::class);
});

it('initializes editable sets from the individual planned sets', function () use ($createExercise) {
    $exercise = $createExercise();

    expect($exercise->status)->toBe(WorkoutExerciseStatus::Pending);
    expect($exercise->plannedSets())->toHaveCount(3);
    expect($exercise->workoutSets())->toHaveCount(3);
});

it('replaces the complete progress snapshot', function () use ($createExercise, $changedSets) {
    $exercise = $createExercise();

    $exercise->saveProgress($changedSets());

    expect(array_map(
        static fn (WorkoutSet $set): array => [$set->repetitions->value, $set->workingWeight->grams],
        $exercise->workoutSets(),
    ))->toBe([
        [8, 90_000],
        [8, 100_000],
        [7, 100_000],
        [6, 95_000],
    ]);
});

it('allows temporarily saving an empty progress snapshot', function () use ($createExercise) {
    $exercise = $createExercise();

    $exercise->saveProgress(new WorkoutSetCollection);

    expect($exercise->workoutSets())->toBe([]);
});

it('completes an exercise with at least one saved set', function () use ($createExercise, $changedSets) {
    $exercise = $createExercise();
    $exercise->saveProgress($changedSets());

    $exercise->complete($changedSets());

    expect($exercise->status)->toBe(WorkoutExerciseStatus::Completed);
});

it('allows retrying completion with the same sets', function () use ($createExercise, $changedSets) {
    $exercise = $createExercise();
    $exercise->complete($changedSets());

    $exercise->complete($changedSets());

    expect($exercise->status)->toBe(WorkoutExerciseStatus::Completed);
    expect($exercise->workoutSets())->toHaveCount(4);
});

it('rejects retrying completion with different sets', function () use ($createExercise, $changedSets) {
    $exercise = $createExercise();
    $exercise->complete($changedSets());
    $differentSets = new WorkoutSetCollection(new WorkoutSet(
        new SetPosition(1),
        new Repetitions(5),
        new WorkingWeight(110_000),
    ));

    expect(fn () => $exercise->complete($differentSets))
        ->toThrow(WorkoutExerciseIsNotEditable::class);
});

it('rejects completing an exercise without saved sets', function () use ($createExercise) {
    $exercise = $createExercise();
    $exercise->saveProgress(new WorkoutSetCollection);

    expect(fn () => $exercise->complete(new WorkoutSetCollection))
        ->toThrow(WorkoutExerciseHasNoSets::class);
});

it('keeps the previous draft when final completion sets are empty', function () use ($createExercise, $changedSets) {
    $exercise = $createExercise();
    $exercise->saveProgress($changedSets());

    expect(fn () => $exercise->complete(new WorkoutSetCollection))
        ->toThrow(WorkoutExerciseHasNoSets::class);
    expect($exercise->status)->toBe(WorkoutExerciseStatus::Pending);
    expect($exercise->workoutSets())->toHaveCount(4);
});

it('clears saved progress when an exercise is skipped', function () use ($createExercise, $changedSets) {
    $exercise = $createExercise();
    $exercise->saveProgress($changedSets());

    $exercise->skip();

    expect($exercise->status)->toBe(WorkoutExerciseStatus::Skipped);
    expect($exercise->workoutSets())->toBe([]);
});

it('allows retrying skip for an already skipped exercise', function () use ($createExercise) {
    $exercise = $createExercise();
    $exercise->skip();

    $exercise->skip();

    expect($exercise->status)->toBe(WorkoutExerciseStatus::Skipped);
    expect($exercise->workoutSets())->toBe([]);
});

it('reopens a completed exercise for editing', function () use ($createExercise) {
    $exercise = $createExercise();
    $exercise->complete(new WorkoutSetCollection(...$exercise->workoutSets()));

    $exercise->reopen();

    expect($exercise->status)->toBe(WorkoutExerciseStatus::Pending);
});

it('reopens a skipped exercise for editing', function () use ($createExercise) {
    $exercise = $createExercise();
    $exercise->skip();

    $exercise->reopen();

    expect($exercise->status)->toBe(WorkoutExerciseStatus::Pending);
    expect(array_map(
        static fn (WorkoutSet $set): array => [
            $set->position->value,
            $set->repetitions->value,
            $set->workingWeight->grams,
        ],
        $exercise->workoutSets(),
    ))->toBe([
        [1, 8, 90_000],
        [2, 8, 90_000],
        [3, 8, 90_000],
    ]);
});

it('rejects progress changes until a resolved exercise is reopened', function () use ($createExercise, $changedSets) {
    $exercise = $createExercise();
    $exercise->complete(new WorkoutSetCollection(...$exercise->workoutSets()));

    expect(fn () => $exercise->saveProgress($changedSets()))
        ->toThrow(WorkoutExerciseIsNotEditable::class);
});

it('rejects restoring a skipped exercise with saved sets', function () use ($createExercise) {
    $exercise = $createExercise();

    expect(fn () => WorkoutExercise::restore(
        $exercise->snapshot,
        new WorkoutSetCollection(...$exercise->plannedSets()),
        new WorkoutSetCollection(...$exercise->workoutSets()),
        WorkoutExerciseStatus::Skipped,
    ))->toThrow(InvalidWorkoutExerciseState::class);
});
