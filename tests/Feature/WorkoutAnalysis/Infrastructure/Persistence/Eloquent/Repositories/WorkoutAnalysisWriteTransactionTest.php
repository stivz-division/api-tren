<?php

use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Repositories\EloquentWorkoutAnalysisRepository;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture;

uses(DatabaseMigrations::class);

it('rejects adding an analysis without an enclosing transaction', function (): void {
    $analysis = WorkoutAnalysis::initialize(
        WorkoutAnalysisFixture::workout(WorkoutAnalysisFixture::exercise()),
        new DateTimeImmutable('2026-09-17T12:00:00Z'),
    );

    expect(fn () => app(EloquentWorkoutAnalysisRepository::class)->add($analysis))
        ->toThrow(LogicException::class, 'Сохранение анализа тренировки должно выполняться внутри AnalysisTransaction.');
    $this->assertDatabaseCount('workout_analyses', 0);
});

it('rejects saving an analysis without an enclosing transaction', function (): void {
    $analysis = WorkoutAnalysis::initialize(
        WorkoutAnalysisFixture::workout(WorkoutAnalysisFixture::exercise()),
        new DateTimeImmutable('2026-09-17T12:00:00Z'),
    );
    $persisted = WorkoutAnalysis::restore(new WorkoutAnalysisId(1), $analysis->deviations());

    expect(fn () => app(EloquentWorkoutAnalysisRepository::class)->save($persisted))
        ->toThrow(LogicException::class, 'Сохранение анализа тренировки должно выполняться внутри AnalysisTransaction.');
    $this->assertDatabaseCount('workout_analyses', 0);
});
