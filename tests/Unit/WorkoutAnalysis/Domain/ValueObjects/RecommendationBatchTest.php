<?php

use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationBatch;

it('rejects invalid provider metadata and blank rejection reasons', function (?string $model, ?string $responseId, int $promptVersion, int $schemaVersion, ?string $rejectedReason): void {
    expect(fn () => new RecommendationBatch([], 'План подходит.', $rejectedReason === null ? [] : [$rejectedReason], $model, $responseId, $promptVersion, $schemaVersion))->toThrow(InvalidArgumentException::class);
})->with([
    [null, null, 0, 1, null],
    [null, null, 1, 0, null],
    ['model', null, 1, 1, null],
    [null, 'response', 1, 1, null],
    [' ', 'response', 1, 1, null],
    ['model', ' ', 1, 1, null],
    [null, null, 1, 1, ' '],
]);

it('rejects nonlist rejection reasons to preserve the JSON contract', function (): void {
    expect(fn () => new RecommendationBatch([], 'План подходит.', [1 => 'reason']))->toThrow(InvalidArgumentException::class);
});
