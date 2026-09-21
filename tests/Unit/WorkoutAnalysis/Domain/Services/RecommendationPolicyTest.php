<?php

use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\Enums\ExerciseCompletionStatus;
use App\WorkoutAnalysis\Domain\Services\RecommendationAdmissionPolicy;
use App\WorkoutAnalysis\Domain\Services\RecommendationCounterCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisEvidenceReference;
use App\WorkoutAnalysis\Domain\ValueObjects\ExercisePerformanceSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationBatch;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationEligibility;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationProposal;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAIResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('enforces exact eligibility boundaries', function (int $successes, int $failures, int $replacement, int $rejection, bool $successful, string $type, bool $allowed): void {
    expect((new RecommendationEligibility(10, $successes, $failures, $replacement, $rejection, $successful))->allows($type))->toBe($allowed);
})->with([
    [2, 0, 0, 4, true, 'progression', false], [3, 0, 0, 4, true, 'progression', true],
    [0, 1, 0, 4, false, 'adjustment', false], [0, 2, 0, 4, false, 'adjustment', true],
    [0, 2, 0, 3, false, 'replacement', false], [0, 2, 0, 4, false, 'replacement', true],
    [1, 0, 27, 4, true, 'replacement', false], [1, 0, 28, 4, true, 'replacement', true],
    [0, 1, 28, 4, false, 'replacement', false],
]);

it('counts all same plan successes beyond the analysis history window and event intervals', function (): void {
    $history = [];
    for ($i = 1; $i <= 30; $i++) {
        $history[] = Fixture::result(sessionId: $i, completedAt: sprintf('2026-08-%02dT12:00:00Z', $i))->snapshot;
    }
    $result = (new RecommendationCounterCalculator)->calculate(10, Fixture::sets([[10, 50000]]), array_reverse($history),
        new DateTimeImmutable('2026-08-02T12:00:00Z'), new DateTimeImmutable('2026-08-26T12:00:00Z'));
    expect($result->successes)->toBe(30);
    expect($result->failures)->toBe(0);
    expect($result->completedSinceReplacement)->toBe(28);
    expect($result->completedSinceRejection)->toBe(4);
});

it('requires every planned position despite higher total volume and permits extra actual sets', function (ExercisePerformanceSnapshot $exercise, int $successes, int $failures): void {
    $result = (new RecommendationCounterCalculator)->calculate(10, $exercise->plannedSets, [Fixture::workout($exercise)]);
    expect($result->successes)->toBe($successes);
    expect($result->failures)->toBe($failures);
})->with([
    'missing position' => [fn () => Fixture::exercise(planned: [[10, 50000], [10, 50000]], actual: [[20, 100000]]), 0, 1],
    'repetition deficit' => [fn () => Fixture::exercise(planned: [[10, 50000], [10, 50000]], actual: [[20, 100000], [9, 50000]]), 0, 1],
    'weight deficit' => [fn () => Fixture::exercise(planned: [[10, 50000], [10, 50000]], actual: [[10, 50000], [10, 49000]]), 0, 1],
    'extra set' => [fn () => Fixture::exercise(planned: [[10, 50000], [10, 50000]], actual: [[10, 50000], [10, 50000], [1, 1000]]), 1, 0],
]);

it('breaks streaks on changed plans and manual change back barriers and counts skips as failure', function (): void {
    $old = Fixture::result(sessionId: 1, completedAt: '2026-09-10T12:00:00Z')->snapshot;
    $different = Fixture::result(sessionId: 2, completedAt: '2026-09-11T12:00:00Z', exercise: Fixture::exercise(planned: [[9, 50000]]))->snapshot;
    $current = Fixture::result(sessionId: 3, completedAt: '2026-09-12T12:00:00Z')->snapshot;
    $calculator = new RecommendationCounterCalculator;
    expect($calculator->calculate(10, Fixture::sets([[10, 50000]]), [$old, $different, $current])->successes)->toBe(1);
    expect($calculator->calculate(10, Fixture::sets([[10, 50000]]), [$old, $current], lastPlanChangeAt: new DateTimeImmutable('2026-09-11T12:00:00Z'))->successes)->toBe(1);
    $skipped = Fixture::exercise(actual: [], status: ExerciseCompletionStatus::Skipped);
    expect($calculator->calculate(10, $skipped->plannedSets, [Fixture::workout($skipped)])->failures)->toBe(1);
});

$analysis = static fn (): WorkoutAIResult => new WorkoutAIResult(new WorkoutAnalysisId(91), new AnalysisContextSnapshot(
    Fixture::result(), new WorkoutHistoryWindow(1), new WorkoutHistoryWindow(1), new DateTimeImmutable('2026-09-15T12:01:00Z')),
    'Выполнено.', 'Достаточно истории.', 'model', 'response', 1, 1);
$program = static fn (): array => ['program_id' => 11, 'exercises' => [['exercise_id' => 10,
    'sets' => [['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 50000]],
    'successes' => 3, 'failures' => 2, 'completed_since_replacement' => 28, 'completed_since_rejection' => 4, 'currently_successful' => true]],
    'catalog' => [['id' => 10, 'name' => 'Жим'], ['id' => 11, 'name' => 'Другой жим']]];
$proposal = static fn (string $type = 'progression', ?int $target = null, int $session = 51): RecommendationProposal => new RecommendationProposal(
    10, $type, $target, Fixture::sets([[10, 52500]]), 'Три успеха.', new AnalysisEvidenceReference(new WorkoutAnalysisId(91), new WorkoutSessionId($session)));

it('admits grounded eligible whole proposals', function () use ($analysis, $program, $proposal): void {
    $result = (new RecommendationAdmissionPolicy)->admit(new RecommendationBatch([$proposal()]), $analysis(), $program());
    expect($result->proposals)->toHaveCount(1);
    expect($result->rejectedReasons)->toBe([]);
});

it('rejects conflicting exercise proposals together and rejects invented evidence or existing replacement target', function () use ($analysis, $program, $proposal): void {
    $policy = new RecommendationAdmissionPolicy;
    $duplicates = $policy->admit(new RecommendationBatch([$proposal(), $proposal('adjustment')]), $analysis(), $program());
    expect($duplicates->proposals)->toBe([]);
    expect($duplicates->rejectedReasons)->toHaveCount(2);
    expect($policy->admit(new RecommendationBatch([$proposal(session: 999)]), $analysis(), $program())->rejectedReasons)->toBe(['10:invalid_evidence']);
    expect($policy->admit(new RecommendationBatch([$proposal('replacement', 10)]), $analysis(), $program())->rejectedReasons)->toBe(['10:replacement_conflict']);
});

it('requires a reason when no change is proposed', function (): void {
    expect(fn () => new RecommendationBatch([]))->toThrow(InvalidArgumentException::class);
    expect(new RecommendationBatch([], 'План пока сохраняется.'))->noChangeReason->toBe('План пока сохраняется.');
});

it('admits a whole mixed adjustment after two failures without requiring progression eligibility', function () use ($analysis, $program): void {
    $context = $program();
    $context['exercises'][0]['successes'] = 0;
    $context['exercises'][0]['currently_successful'] = false;
    $sets = Fixture::sets([[8, 55000]]);
    $proposal = new RecommendationProposal(10, 'adjustment', null, $sets, 'Уменьшить повторы с повышением веса.',
        new AnalysisEvidenceReference(new WorkoutAnalysisId(91), new WorkoutSessionId(51)));

    $result = (new RecommendationAdmissionPolicy)->admit(new RecommendationBatch([$proposal]), $analysis(), $context);

    expect($result->rejectedReasons)->toBe([]);
    expect($result->proposals)->toBe([$proposal]);
    expect($result->proposals[0]->proposedSets)->toBe($sets);
    expect($result->proposals[0]->proposedSets->all()[0]->repetitions->value)->toBe(8);
    expect($result->proposals[0]->proposedSets->all()[0]->workingWeight->grams)->toBe(55000);
});

it('admits ordinary replacement after 28 completions with interrupted successes when the current plan is fulfilled', function () use ($analysis, $program, $proposal): void {
    $history = [];
    for ($day = 1; $day <= 28; $day++) {
        $history[] = Fixture::result(sessionId: $day, completedAt: sprintf('2026-08-%02dT12:00:00Z', $day),
            exercise: Fixture::exercise(actual: [[$day % 2 === 0 ? 10 : 8, 50000]]))->snapshot;
    }
    $eligibility = (new RecommendationCounterCalculator)->calculate(10, Fixture::sets([[10, 50000]]), $history,
        lastReplacementAt: new DateTimeImmutable('2026-07-31T12:00:00Z'));
    $context = $program();
    $context['exercises'][0]['successes'] = $eligibility->successes;
    $context['exercises'][0]['failures'] = $eligibility->failures;
    $context['exercises'][0]['completed_since_replacement'] = $eligibility->completedSinceReplacement;
    $context['exercises'][0]['completed_since_rejection'] = $eligibility->completedSinceRejection;
    $context['exercises'][0]['currently_successful'] = $eligibility->currentlySuccessful;

    $result = (new RecommendationAdmissionPolicy)->admit(new RecommendationBatch([$proposal('replacement', 11)]), $analysis(), $context);

    expect($eligibility->successes)->toBe(1);
    expect($eligibility->failures)->toBe(0);
    expect($eligibility->completedSinceReplacement)->toBe(28);
    expect($eligibility->currentlySuccessful)->toBeTrue();
    expect($result->rejectedReasons)->toBe([]);
    expect($result->proposals)->toHaveCount(1);
    expect($result->proposals[0]->replacementExerciseId)->toBe(11);
});
