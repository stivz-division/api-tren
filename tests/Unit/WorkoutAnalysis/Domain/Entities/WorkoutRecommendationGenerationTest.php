<?php

use App\WorkoutAnalysis\Domain\Entities\WorkoutRecommendationGeneration;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationBatch;

it('preserves frozen program context through timeout recovery and manual retry', function (): void {
    $now = new DateTimeImmutable('2026-09-21T12:00:00Z');
    $stage = WorkoutRecommendationGeneration::pending($now);
    $stage->captureProgramContext(['program_id' => 1, 'exercises' => [], 'catalog' => []]);
    expect($stage->start(1, $now, $now->modify('+120 seconds')))->toBeTrue();
    expect($stage->start(1, $now, $now->modify('+120 seconds')))->toBeFalse();
    expect($stage->recoverExpired($now->modify('+120 seconds'), null))->toBeTrue();
    expect($stage->status())->toBe(AnalysisStatus::Failed);
    expect($stage->retry($now->modify('+121 seconds')))->toBeTrue();
    expect($stage->currentAttempt()->cycleAttempt)->toBe(1);
    expect($stage->programContext)->toBe(['program_id' => 1, 'exercises' => [], 'catalog' => []]);
    expect(fn () => $stage->captureProgramContext(['program_id' => 2, 'exercises' => [], 'catalog' => []]))->toThrow(InvalidArgumentException::class);
    expect($stage->fail(1, AnalysisFailureCode::WorkerFailed, $now->modify('+122 seconds'), null))->toBeFalse();
});

it('rejects late completion at the exact deadline and preserves a successful result on duplicate delivery', function (): void {
    $now = new DateTimeImmutable('2026-09-21T12:00:00Z');
    $stage = WorkoutRecommendationGeneration::pending($now);
    $context = ['program_id' => 1, 'exercises' => [], 'catalog' => []];
    $stage->captureProgramContext($context);
    $result = new RecommendationBatch([], 'Изменений не требуется.');
    $stage->start(1, $now, $now->modify('+120 seconds'));
    expect($stage->complete(1, $result, $now->modify('+120 seconds')))->toBeFalse();
    expect($stage->complete(1, $result, $now->modify('+119 seconds')))->toBeTrue();
    expect($stage->complete(1, $result, $now->modify('+119 seconds')))->toBeFalse();
    expect(WorkoutRecommendationGeneration::restore($stage->attempts(), $result, $context))->toEqual($stage);
    expect(fn () => WorkoutRecommendationGeneration::restore($stage->attempts(), $result))->toThrow(InvalidArgumentException::class);
    expect(fn () => WorkoutRecommendationGeneration::restore($stage->attempts(), $result, $context, ['unexpected']))->toThrow(InvalidArgumentException::class);
});

it('refuses to mark a fully rejected batch completed', function (): void {
    $now = new DateTimeImmutable('2026-09-21T12:00:00Z');
    $stage = WorkoutRecommendationGeneration::pending($now);
    $stage->captureProgramContext(['program_id' => 1, 'exercises' => [], 'catalog' => []]);
    $stage->start(1, $now, $now->modify('+120 seconds'));
    $batch = new RecommendationBatch([], null, ['10:eligibility_not_met']);
    expect(fn () => $stage->complete(1, $batch, $now))->toThrow(InvalidArgumentException::class);
    expect($stage->status())->toBe(AnalysisStatus::Processing);
});
