<?php

use App\WorkoutAnalysis\Application\DTO\CompletedWorkoutData;
use App\WorkoutAnalysis\Application\DTO\HistoricalWorkoutData;
use App\WorkoutAnalysis\Application\DTO\WorkoutHistoryData;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutAnalysisNotFound;
use App\WorkoutAnalysis\Application\Exceptions\WorkoutDeviationsNotReady;
use App\WorkoutAnalysis\Application\Factories\AnalysisContextSnapshotFactory;
use App\WorkoutAnalysis\Application\Factories\CompletedWorkoutSnapshotFactory;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\Policies\AnalysisHistoryPolicy;
use App\WorkoutAnalysis\Application\UseCases\CalculateWorkoutDeviations\CalculateWorkoutDeviationsInput;
use App\WorkoutAnalysis\Application\UseCases\InitializeWorkoutAnalysis\InitializeWorkoutAnalysisInput;
use App\WorkoutAnalysis\Application\UseCases\PrepareWorkoutAnalysisContext\PrepareWorkoutAnalysisContext;
use App\WorkoutAnalysis\Application\UseCases\PrepareWorkoutAnalysisContext\PrepareWorkoutAnalysisContextInput;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use Tests\Support\WorkoutAnalysis\AnalysisUseCases;
use Tests\Support\WorkoutAnalysis\InMemoryAnalysisEnvironment;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture as Fixture;

$ready = static function (): AnalysisUseCases {
    $app = new AnalysisUseCases(new InMemoryAnalysisEnvironment);
    $app->initialize()->handle(new InitializeWorkoutAnalysisInput(7, 51));
    $app->calculate()->handle(new CalculateWorkoutDeviationsInput(7, 1, 1));

    return $app;
};

it('prepares and stores context from the completed analysis without rereading the current program', function () use ($ready) {
    $app = $ready();
    $app->env->source = null;
    $saves = $app->env->saves;

    $context = $app->prepareContext()->handle(new PrepareWorkoutAnalysisContextInput(7, 1));

    expect($context->currentWorkout->snapshot->workoutSessionId->value)->toBe(51);
    expect($context->sameProgram)->toHaveCount(0);
    expect($context->otherPrograms)->toHaveCount(0);
    expect($app->env->findForUser(new WorkoutAnalysisId(1), new UserId(7))?->context())->toBe($context);
    expect($app->env->saves)->toBe($saves + 1);
    expect($app->env->sourceReads)->toBe(1);
    expect($app->env->tasks)->toHaveCount(1);
});

it('requests independent limits with the current workout completion as the strict history boundary', function () use ($ready) {
    $app = $ready();

    $context = $app->prepareContext(new AnalysisHistoryPolicy(28, 5))->handle(new PrepareWorkoutAnalysisContextInput(7, 1));

    expect($app->env->historyQueries)->toHaveCount(1);
    $query = $app->env->historyQueries[0];
    expect($query->userId)->toBe(7);
    expect($query->trainingProgramId)->toBe(11);
    expect($query->currentWorkoutSessionId)->toBe(51);
    expect($query->completedBefore)->toEqual(new DateTimeImmutable('2026-09-15 12:00:00+00:00'));
    expect($query->sameProgramLimit)->toBe(28);
    expect($query->otherProgramsLimit)->toBe(5);
    expect($context->sameProgram->limit)->toBe(28);
    expect($context->otherPrograms->limit)->toBe(5);
});

it('fixes the capture time after reading the selected history', function () use ($ready) {
    $app = $ready();
    $app->env->onHistoryRead = function () use ($app): void {
        $app->env->time = $app->env->time->modify('+5 seconds');
    };

    $context = $app->prepareContext()->handle(new PrepareWorkoutAnalysisContextInput(7, 1));

    expect($context->capturedAt)->toEqual(new DateTimeImmutable('2026-09-17 12:00:05+00:00'));
});

it('reuses the frozen context without reading newer history or changing its limits', function () use ($ready) {
    $app = $ready();
    $first = $app->prepareContext()->handle(new PrepareWorkoutAnalysisContextInput(7, 1));
    $saves = $app->env->saves;
    $app->env->failHistoryRead = true;
    $app->env->time = $app->env->time->modify('+1 day');

    $second = $app->prepareContext(new AnalysisHistoryPolicy(1, 2))->handle(new PrepareWorkoutAnalysisContextInput(7, 1));

    expect($second)->toBe($first);
    expect($second->sameProgram->limit)->toBe(20);
    expect($app->env->historyQueries)->toHaveCount(1);
    expect($app->env->saves)->toBe($saves);
});

it('returns the same not found error for another user and a missing analysis', function (int $userId, int $analysisId) use ($ready) {
    $app = $ready();
    $saves = $app->env->saves;

    expect(fn () => $app->prepareContext()->handle(new PrepareWorkoutAnalysisContextInput($userId, $analysisId)))->toThrow(WorkoutAnalysisNotFound::class);
    expect($app->env->historyQueries)->toBe([]);
    expect($app->env->saves)->toBe($saves);
})->with(['foreign' => [8, 1], 'missing' => [7, 99]]);

it('requires completed deviations before reading history', function () {
    $app = new AnalysisUseCases(new InMemoryAnalysisEnvironment);
    $app->initialize()->handle(new InitializeWorkoutAnalysisInput(7, 51));
    $saves = $app->env->saves;

    expect(fn () => $app->prepareContext()->handle(new PrepareWorkoutAnalysisContextInput(7, 1)))->toThrow(WorkoutDeviationsNotReady::class);
    expect($app->env->historyQueries)->toBe([]);
    expect($app->env->saves)->toBe($saves);
    expect($app->env->findForUser(new WorkoutAnalysisId(1), new UserId(7))?->deviations()->status())->toBe(AnalysisStatus::Pending);
});

it('does not convert history read failures to an empty context', function () use ($ready) {
    $app = $ready();
    $app->env->failHistoryRead = true;
    $saves = $app->env->saves;

    expect(fn () => $app->prepareContext()->handle(new PrepareWorkoutAnalysisContextInput(7, 1)))->toThrow(RuntimeException::class, 'History unavailable.');
    expect($app->env->findForUser(new WorkoutAnalysisId(1), new UserId(7))?->context())->toBeNull();
    expect($app->env->saves)->toBe($saves);
});

it('does not leave attached context behind when saving fails', function () use ($ready) {
    $app = $ready();
    $app->env->failSave = true;

    expect(fn () => $app->prepareContext()->handle(new PrepareWorkoutAnalysisContextInput(7, 1)))->toThrow(RuntimeException::class, 'Storage unavailable.');
    expect($app->env->findForUser(new WorkoutAnalysisId(1), new UserId(7))?->context())->toBeNull();
    expect($app->env->findForUser(new WorkoutAnalysisId(1), new UserId(7))?->deviations()->status())->toBe(AnalysisStatus::Completed);
});

it('does not persist invalid history or discard its offending entries', function () use ($ready) {
    $app = $ready();
    $app->env->history = new WorkoutHistoryData([new HistoricalWorkoutData(InMemoryAnalysisEnvironment::data(Fixture::result()->snapshot))], []);
    $saves = $app->env->saves;

    expect(fn () => $app->prepareContext()->handle(new PrepareWorkoutAnalysisContextInput(7, 1)))->toThrow(InvalidAnalysisContext::class);
    expect($app->env->findForUser(new WorkoutAnalysisId(1), new UserId(7))?->context())->toBeNull();
    expect($app->env->saves)->toBe($saves);
});

it('calculates old workout deviations without initializing or scheduling an old analysis', function () use ($ready) {
    $app = $ready();
    $previous = Fixture::result(sessionId: 50, completedAt: '2026-09-14 12:00:00+00:00');
    $app->env->history = new WorkoutHistoryData([new HistoricalWorkoutData(InMemoryAnalysisEnvironment::data($previous->snapshot))], []);

    $context = $app->prepareContext()->handle(new PrepareWorkoutAnalysisContextInput(7, 1));

    expect($context->sameProgram->all()[0]->deviations)->toEqual($previous);
    expect($app->env->findForSession($previous->snapshot->workoutSessionId, new UserId(7)))->toBeNull();
    expect($app->env->tasks)->toHaveCount(1);
});

it('reads the winning context after acquiring the transaction instead of preparing it again', function () use ($ready) {
    $app = $ready();
    $transaction = new class($app) implements AnalysisTransaction
    {
        public ?AnalysisContextSnapshot $winner = null;

        public function __construct(private AnalysisUseCases $app) {}

        public function execute(UserId $userId, Closure $callback): mixed
        {
            $this->winner = $this->app->prepareContext()->handle(new PrepareWorkoutAnalysisContextInput(7, 1));

            return $this->app->env->execute($userId, $callback);
        }

        public function afterCommit(Closure $callback): void
        {
            $this->app->env->afterCommit($callback);
        }
    };
    $prepare = new PrepareWorkoutAnalysisContext($app->env, $app->env->historyProvider(), new AnalysisContextSnapshotFactory(new CompletedWorkoutSnapshotFactory, new WorkoutDeviationCalculator), $app->env, $transaction, new AnalysisHistoryPolicy);
    $saves = $app->env->saves;

    $context = $prepare->handle(new PrepareWorkoutAnalysisContextInput(7, 1));

    expect($context)->toBe($transaction->winner);
    expect($app->env->historyQueries)->toHaveCount(1);
    expect($app->env->saves)->toBe($saves + 1);
});

it('does not publish context when a missing historical calculation overflows', function () use ($ready) {
    $app = $ready();
    $source = InMemoryAnalysisEnvironment::data(Fixture::workout(Fixture::exercise(planned: [[2, PHP_INT_MAX]], actual: [[1, 0]])));
    $historical = new CompletedWorkoutData(50, 7, 11, $source->programName, 'completed', new DateTimeImmutable('2026-09-14 12:00:00+00:00'), $source->exercises);
    $app->env->history = new WorkoutHistoryData([new HistoricalWorkoutData($historical)], []);
    $saves = $app->env->saves;

    expect(fn () => $app->prepareContext()->handle(new PrepareWorkoutAnalysisContextInput(7, 1)))->toThrow(OverflowException::class);
    expect($app->env->findForUser(new WorkoutAnalysisId(1), new UserId(7))?->context())->toBeNull();
    expect($app->env->findForUser(new WorkoutAnalysisId(1), new UserId(7))?->deviations()->status())->toBe(AnalysisStatus::Completed);
    expect($app->env->saves)->toBe($saves);
    expect($app->env->tasks)->toHaveCount(1);
});

it('leaves no context when the enclosing transaction rolls back', function () use ($ready) {
    $app = $ready();

    expect(fn () => $app->env->execute(new UserId(7), function () use ($app): void {
        $app->prepareContext()->handle(new PrepareWorkoutAnalysisContextInput(7, 1));
        throw new RuntimeException('Rollback after preparation.');
    }))->toThrow(RuntimeException::class, 'Rollback after preparation.');

    expect($app->env->findForUser(new WorkoutAnalysisId(1), new UserId(7))?->context())->toBeNull();
    expect($app->env->tasks)->toHaveCount(1);
});

it('does not prepare context for a processing or failed deviation stage', function (bool $failed) {
    $app = new AnalysisUseCases(new InMemoryAnalysisEnvironment);
    $app->initialize()->handle(new InitializeWorkoutAnalysisInput(7, 51));
    $app->env->execute(new UserId(7), function () use ($app, $failed): void {
        $analysis = $app->env->findForUser(new WorkoutAnalysisId(1), new UserId(7)) ?? throw new LogicException;
        $analysis->startDeviationAttempt(1, $app->env->time, $app->env->time->modify('+2 minutes'));
        if ($failed) {
            $analysis->failDeviationAttempt(1, AnalysisFailureCode::CalculationFailed, $app->env->time, null);
        }
        $app->env->save($analysis);
    });
    $saves = $app->env->saves;

    expect(fn () => $app->prepareContext()->handle(new PrepareWorkoutAnalysisContextInput(7, 1)))->toThrow(WorkoutDeviationsNotReady::class);
    expect($app->env->historyQueries)->toBe([]);
    expect($app->env->saves)->toBe($saves);
})->with(['processing' => false, 'failed' => true]);
