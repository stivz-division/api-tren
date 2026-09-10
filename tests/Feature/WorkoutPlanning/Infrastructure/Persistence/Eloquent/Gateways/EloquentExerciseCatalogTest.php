<?php

use App\Models\Exercise;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Gateways\EloquentExerciseCatalog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('returns only exercise identities missing from the catalog', function (): void {
    $benchPress = Exercise::factory()->create();
    $squat = Exercise::factory()->create();
    $catalog = new EloquentExerciseCatalog;

    $missingExerciseIds = $catalog->findMissing([
        new ExerciseId($benchPress->id),
        new ExerciseId(999_998),
        new ExerciseId($squat->id),
        new ExerciseId(999_999),
    ]);

    expect(array_map(
        static fn (ExerciseId $exerciseId): int => $exerciseId->value,
        $missingExerciseIds,
    ))->toBe([999_998, 999_999]);
});
