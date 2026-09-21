<?php

use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisEvidenceReference;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalAIConclusion;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;

it('preserves both sections and evidence pointing outside the current history window', function () {
    $reference = new AnalysisEvidenceReference(new WorkoutAnalysisId(9), new WorkoutSessionId(1));

    $conclusion = new HistoricalAIConclusion(new WorkoutAnalysisId(9), new WorkoutSessionId(50), 'План выполнен.', 'Нагрузка выросла.', $reference);

    expect($conclusion->currentWorkout)->toBe('План выполнен.');
    expect($conclusion->history)->toBe('Нагрузка выросла.');
    expect($conclusion->evidence)->toBe([$reference]);
});

it('rejects an empty conclusion section', function (string $currentWorkout, string $history) {
    expect(fn () => new HistoricalAIConclusion(new WorkoutAnalysisId(9), new WorkoutSessionId(50), $currentWorkout, $history))
        ->toThrow(InvalidAnalysisContext::class);
})->with(['current workout' => ['  ', 'Истории нет.'], 'history' => ['План выполнен.', "\n"]]);

it('rejects evidence attributed to a different source analysis', function () {
    $reference = new AnalysisEvidenceReference(new WorkoutAnalysisId(10), new WorkoutSessionId(1));

    expect(fn () => new HistoricalAIConclusion(new WorkoutAnalysisId(9), new WorkoutSessionId(50), 'План выполнен.', 'Истории нет.', $reference))
        ->toThrow(InvalidAnalysisContext::class);
});
