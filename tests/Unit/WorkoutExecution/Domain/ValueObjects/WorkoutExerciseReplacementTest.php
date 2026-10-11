<?php

use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\Exceptions\InvalidWorkoutExerciseReplacement;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseName;
use App\WorkoutExecution\Domain\ValueObjects\ExercisePosition;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutExerciseReplacement;
use Tests\Support\WorkoutExecution\WorkoutSessionFixture;

it('normalizes the request UUID and stores the operation time in UTC', function () {
    $replacement = new WorkoutExerciseReplacement(
        1, '550E8400-E29B-41D4-A716-000000000001',
        new ExerciseSnapshot(new ExerciseId(30), new ExerciseName('Новое'), new ExercisePosition(1)),
        WorkoutSessionFixture::sets(), false, new DateTimeImmutable('2026-10-09 19:00:00 +07:00'),
    );

    expect($replacement->requestId)->toBe('550e8400-e29b-41d4-a716-000000000001');
    expect($replacement->createdAt->format('Y-m-d H:i:s T'))->toBe('2026-10-09 12:00:00 UTC');
    expect($replacement->plannedSets())->toHaveCount(3);
});

it('rejects malformed replacement identity and revision values', function (int $sequence, string $requestId, bool $permanent, ?int $revision) {
    expect(fn () => new WorkoutExerciseReplacement(
        $sequence, $requestId,
        new ExerciseSnapshot(new ExerciseId(30), new ExerciseName('Новое'), new ExercisePosition(1)),
        WorkoutSessionFixture::sets(), $permanent, new DateTimeImmutable('2026-10-09 UTC'), $revision,
    ))->toThrow(InvalidWorkoutExerciseReplacement::class);
})->with([
    'zero sequence' => [0, '550e8400-e29b-41d4-a716-000000000001', false, null],
    'invalid UUID' => [1, 'invalid', false, null],
    'negative revision' => [1, '550e8400-e29b-41d4-a716-000000000001', true, -1],
    'revision on temporary operation' => [1, '550e8400-e29b-41d4-a716-000000000001', false, 1],
]);

it('preserves ordered planned sets when their input order differs', function () {
    $sets = WorkoutSessionFixture::sets()->all();
    $replacement = new WorkoutExerciseReplacement(
        1, '550e8400-e29b-41d4-a716-000000000001',
        new ExerciseSnapshot(new ExerciseId(30), new ExerciseName('Новое'), new ExercisePosition(1)),
        new WorkoutSetCollection(...array_reverse($sets)), true, new DateTimeImmutable('2026-10-09 UTC'), 2,
    );

    expect($replacement->matchesPayload(new ExerciseId(30), true, WorkoutSessionFixture::sets()))->toBeTrue();
    expect($replacement->plannedSets()[0]->position->value)->toBe(1);
    expect($replacement->planRevisionAfter)->toBe(2);
});
