<?php

use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutHistoryEntry;
use App\WorkoutAnalysis\Infrastructure\Integrations\OpenAI\WorkoutAnalysisFacts;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

it('keeps historical missed sets out of the current workout facts', function (): void {
    $planned = [[12, 20000], [12, 20000], [12, 20000]];
    $context = new AnalysisContextSnapshot(
        Fixture::result(exercise: Fixture::exercise(planned: $planned, actual: $planned)),
        new WorkoutHistoryWindow(3, new WorkoutHistoryEntry(Fixture::result(
            sessionId: 50, completedAt: '2026-09-14T12:00:00Z',
            exercise: Fixture::exercise(planned: $planned, actual: [[12, 20000], [8, 25000]]),
        ))),
        new WorkoutHistoryWindow(3), new DateTimeImmutable('2026-09-15T12:01:00Z'),
    );

    $facts = app(WorkoutAnalysisFacts::class)->catalog($context);

    $current = $facts['current_workout']['current:exercise:10'];
    expect($current['text'])->toContain('№51', 'Без отклонений', '12 × 20 кг')
        ->not->toContain('25 кг', '№50');
    expect($current['evidence'])->toBe([['workout_session_id' => 51, 'exercise_id' => 10]]);
    $history = $facts['history']['history:50:exercise:10'];
    expect($history['text'])->toContain('№50', '№51', '8 × 25 кг', '12 × 20 кг');
    expect($history['evidence'])->toBe([
        ['workout_session_id' => 50, 'exercise_id' => 10],
        ['workout_session_id' => 51, 'exercise_id' => 10],
    ]);
});

it('formats weight and volume in kilograms exactly once including fractional weights', function (): void {
    $context = new AnalysisContextSnapshot(
        Fixture::result(exercise: Fixture::exercise(planned: [[8, 90000]], actual: [[10, 92500]])),
        new WorkoutHistoryWindow(3), new WorkoutHistoryWindow(3), new DateTimeImmutable('2026-09-15T12:01:00Z'),
    );

    $facts = app(WorkoutAnalysisFacts::class)->catalog($context);

    expect($facts['current_workout']['current:summary']['text'])->toContain('720', '925', '+205', 'кг·повторов');
    expect($facts['current_workout']['current:exercise:10']['text'])->toContain('8 × 90 кг', '10 × 92,5 кг');
    expect($facts['history']['history:same_program:empty']['text'])->toContain('нет');
});

it('distinguishes equal totals from matching individual sets', function (): void {
    $context = new AnalysisContextSnapshot(
        Fixture::result(exercise: Fixture::exercise(planned: [[10, 50000], [10, 50000]], actual: [[8, 50000], [12, 50000]])),
        new WorkoutHistoryWindow(3), new WorkoutHistoryWindow(3), new DateTimeImmutable('2026-09-15T12:01:00Z'),
    );

    $facts = app(WorkoutAnalysisFacts::class)->catalog($context);

    expect($facts['current_workout']['current:exercise:10']['text'])->toContain('План не выполнен', '8 × 50 кг', '12 × 50 кг')
        ->not->toContain('Без отклонений');
});

it('rejects history facts in the current section instead of publishing them', function (): void {
    $context = new AnalysisContextSnapshot(
        Fixture::result(),
        new WorkoutHistoryWindow(3, new WorkoutHistoryEntry(Fixture::result(sessionId: 50, completedAt: '2026-09-14T12:00:00Z'))),
        new WorkoutHistoryWindow(3), new DateTimeImmutable('2026-09-15T12:01:00Z'),
    );
    $facts = app(WorkoutAnalysisFacts::class);

    expect(fn () => $facts->render($facts->catalog($context), ['history:50:exercise:10'], ['history:50:summary']))
        ->toThrow(UnexpectedValueException::class);
});

it('renders only server facts and includes every current exercise even when omitted by the model', function (): void {
    $context = new AnalysisContextSnapshot(
        Fixture::result(), new WorkoutHistoryWindow(3), new WorkoutHistoryWindow(3), new DateTimeImmutable('2026-09-15T12:01:00Z'),
    );
    $facts = app(WorkoutAnalysisFacts::class);

    $result = $facts->render($facts->catalog($context), ['current:summary'], ['history:same_program:empty']);

    expect($result['current_workout'])->toContain('№51', '10 × 50 кг', 'Без отклонений');
    expect($result['history'])->toContain('той же программы', 'других программ');
    expect($result['evidence'])->toBe([
        ['workout_session_id' => 51, 'exercise_id' => null],
        ['workout_session_id' => 51, 'exercise_id' => 10],
    ]);
});

it('always includes the most recent historical comparison when the model selects an older one', function (): void {
    $context = new AnalysisContextSnapshot(
        Fixture::result(), new WorkoutHistoryWindow(3,
            new WorkoutHistoryEntry(Fixture::result(sessionId: 49, completedAt: '2026-09-13T12:00:00Z')),
            new WorkoutHistoryEntry(Fixture::result(sessionId: 50, completedAt: '2026-09-14T12:00:00Z')),
        ), new WorkoutHistoryWindow(3), new DateTimeImmutable('2026-09-15T12:01:00Z'),
    );
    $facts = app(WorkoutAnalysisFacts::class);

    $result = $facts->render($facts->catalog($context), ['current:summary'], ['history:49:summary']);

    expect($result['history'])->toContain('№50 → №51');
});

it('rejects invented duplicated or free text fact selections', function (mixed $selection): void {
    $context = new AnalysisContextSnapshot(
        Fixture::result(), new WorkoutHistoryWindow(3), new WorkoutHistoryWindow(3), new DateTimeImmutable('2026-09-15T12:01:00Z'),
    );
    $facts = app(WorkoutAnalysisFacts::class);

    expect(fn () => $facts->render($facts->catalog($context), $selection, ['history:same_program:empty']))
        ->toThrow(UnexpectedValueException::class);
})->with([
    'invented fact' => [['current:exercise:999']],
    'duplicate fact' => [['current:summary', 'current:summary']],
    'free text' => ['Бабочка: выполнен только один подход.'],
    'empty selection' => [[]],
    'nonstring id' => [[42]],
    'object' => [(object) ['fact_id' => 'current:summary']],
]);
