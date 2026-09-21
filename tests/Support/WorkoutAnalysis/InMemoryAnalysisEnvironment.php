<?php

namespace Tests\Support\WorkoutAnalysis;

use App\WorkoutAnalysis\Application\DTO\AIAnalysisTask;
use App\WorkoutAnalysis\Application\DTO\CompletedWorkoutData;
use App\WorkoutAnalysis\Application\DTO\DeviationTask;
use App\WorkoutAnalysis\Application\DTO\ExercisePerformanceData;
use App\WorkoutAnalysis\Application\DTO\SetSnapshotData;
use App\WorkoutAnalysis\Application\DTO\WorkoutHistoryData;
use App\WorkoutAnalysis\Application\DTO\WorkoutHistoryQuery;
use App\WorkoutAnalysis\Application\Gateways\AIAnalysisTaskScheduler;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTaskScheduler;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\Gateways\CompletedWorkoutProvider;
use App\WorkoutAnalysis\Application\Gateways\WorkoutHistoryProvider;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExercisePerformanceSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use Closure;
use DateTimeImmutable;
use LogicException;
use RuntimeException;
use Throwable;

final class InMemoryAnalysisEnvironment implements AnalysisClock, AnalysisTaskScheduler, AnalysisTransaction, WorkoutAnalysisRepository
{
    /** @var array<int, WorkoutAnalysis> */
    private array $analyses = [];

    /** @var list<Closure(): void> */
    private array $callbacks = [];

    /** @var list<DeviationTask> */
    public array $tasks = [];

    /** @var list<AIAnalysisTask> */
    public array $aiTasks = [];

    public function aiScheduler(): AIAnalysisTaskScheduler
    {
        return new class($this) implements AIAnalysisTaskScheduler
        {
            public function __construct(private InMemoryAnalysisEnvironment $env) {}

            public function schedule(AIAnalysisTask $task): void
            {
                $this->env->aiTasks[] = $task;
            }
        };
    }

    public ?CompletedWorkoutData $source;

    public WorkoutHistoryData $history;

    /** @var list<WorkoutHistoryQuery> */
    public array $historyQueries = [];

    public bool $failHistoryRead = false;

    /** @var (Closure(): void)|null */
    public ?Closure $onHistoryRead = null;

    public DateTimeImmutable $time;

    public bool $failDispatch = false;

    public bool $failSave = false;

    public int $depth = 0;

    public int $sourceReads = 0;

    public int $saves = 0;

    private int $nextId = 1;

    public function __construct(?CompletedWorkoutSnapshot $snapshot = null)
    {
        $this->time = new DateTimeImmutable('2026-09-17 12:00:00+00:00');
        $this->source = self::data($snapshot ?? WorkoutAnalysisFixture::workout(WorkoutAnalysisFixture::exercise()));
        $this->history = new WorkoutHistoryData([], []);
    }

    public static function data(CompletedWorkoutSnapshot $snapshot, string $status = 'completed'): CompletedWorkoutData
    {
        return new CompletedWorkoutData(
            $snapshot->workoutSessionId->value,
            $snapshot->userId->value,
            $snapshot->trainingProgramId->value,
            $snapshot->programName->value,
            $status,
            $snapshot->completedAt,
            array_map(static fn (ExercisePerformanceSnapshot $exercise): ExercisePerformanceData => new ExercisePerformanceData(
                $exercise->exerciseId->value,
                $exercise->name->value,
                $exercise->position->value,
                $exercise->status->value,
                array_map(SetSnapshotData::fromDomain(...), $exercise->plannedSets->all()),
                array_map(SetSnapshotData::fromDomain(...), $exercise->actualSets->all()),
            ), $snapshot->exercises->all()),
        );
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }

    public function provider(): CompletedWorkoutProvider
    {
        return new class($this) implements CompletedWorkoutProvider
        {
            public function __construct(private InMemoryAnalysisEnvironment $environment) {}

            public function findForUser(WorkoutSessionId $sessionId, UserId $userId): ?CompletedWorkoutData
            {
                $this->environment->sourceReads++;
                $source = $this->environment->source;

                return $source?->workoutSessionId === $sessionId->value && $source->userId === $userId->value ? $source : null;
            }
        };
    }

    public function findForUser(WorkoutAnalysisId $id, UserId $userId): ?WorkoutAnalysis
    {
        $analysis = $this->analyses[$id->value] ?? null;

        return $analysis?->deviations()->snapshot->userId->value === $userId->value ? clone $analysis : null;
    }

    public function historyProvider(): WorkoutHistoryProvider
    {
        return new class($this) implements WorkoutHistoryProvider
        {
            public function __construct(private InMemoryAnalysisEnvironment $environment) {}

            public function read(WorkoutHistoryQuery $query): WorkoutHistoryData
            {
                if ($this->environment->depth === 0) {
                    throw new LogicException('History read outside transaction.');
                }
                $this->environment->historyQueries[] = $query;
                if ($this->environment->failHistoryRead) {
                    throw new RuntimeException('History unavailable.');
                }
                if ($this->environment->onHistoryRead !== null) {
                    ($this->environment->onHistoryRead)();
                }

                return $this->environment->history;
            }
        };
    }

    public function findForSession(WorkoutSessionId $sessionId, UserId $userId): ?WorkoutAnalysis
    {
        foreach ($this->analyses as $analysis) {
            $snapshot = $analysis->deviations()->snapshot;
            if ($snapshot->workoutSessionId->value === $sessionId->value && $snapshot->userId->value === $userId->value) {
                return clone $analysis;
            }
        }

        return null;
    }

    public function add(WorkoutAnalysis $analysis): WorkoutAnalysis
    {
        $snapshot = $analysis->deviations()->snapshot;
        if ($this->depth === 0 || $analysis->id !== null || $this->findForSession($snapshot->workoutSessionId, $snapshot->userId) !== null) {
            throw new LogicException('Invalid insertion.');
        }

        $stored = WorkoutAnalysis::restore(new WorkoutAnalysisId($this->nextId++), $analysis->deviations());
        $this->save($stored);

        return clone $stored;
    }

    public function save(WorkoutAnalysis $analysis): void
    {
        if ($this->failSave) {
            throw new RuntimeException('Storage unavailable.');
        }
        if ($this->depth === 0 || $analysis->id === null) {
            throw new LogicException('Save outside transaction.');
        }
        $this->analyses[$analysis->id->value] = clone $analysis;
        $this->saves++;
    }

    public function schedule(DeviationTask $task): void
    {
        if ($this->depth !== 0) {
            throw new LogicException('Dispatch before outer commit.');
        }
        if ($this->failDispatch) {
            throw new RuntimeException('Queue unavailable.');
        }
        $this->tasks[] = $task;
    }

    /** @template TResult
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function execute(UserId $userId, Closure $callback): mixed
    {
        $backup = $this->analyses;
        $callbackCount = count($this->callbacks);
        $this->depth++;
        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $this->analyses = $backup;
            $this->callbacks = array_slice($this->callbacks, 0, $callbackCount);
            throw $exception;
        } finally {
            $this->depth--;
        }

        if ($this->depth === 0) {
            $callbacks = $this->callbacks;
            $this->callbacks = [];
            foreach ($callbacks as $afterCommit) {
                $afterCommit();
            }
        }

        return $result;
    }

    public function afterCommit(Closure $callback): void
    {
        if ($this->depth === 0) {
            $callback();

            return;
        }
        $this->callbacks[] = $callback;
    }
}
