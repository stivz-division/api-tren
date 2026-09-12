<?php

namespace Tests\Support\WorkoutExecution;

use App\WorkoutExecution\Domain\Collections\WorkoutExerciseCollection;
use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\Entities\WorkoutExercise;
use App\WorkoutExecution\Domain\Entities\WorkoutSession;
use App\WorkoutExecution\Domain\Enums\WorkoutSessionStatus;
use App\WorkoutExecution\Domain\Exceptions\ActiveWorkoutSessionAlreadyExists;
use App\WorkoutExecution\Domain\Repositories\WorkoutSessionRepository;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;
use InvalidArgumentException;
use LogicException;

final class InMemoryWorkoutSessionRepository implements WorkoutSessionRepository
{
    /** @var array<int, WorkoutSession> */
    private array $sessions = [];

    public private(set) int $addCalls = 0;

    public private(set) int $saveCalls = 0;

    public function __construct(
        private int $nextId = 1,
        WorkoutSession ...$sessions,
    ) {
        if ($this->nextId < 1) {
            throw new InvalidArgumentException('Следующий идентификатор сессии должен быть положительным.');
        }

        foreach ($sessions as $session) {
            $id = $this->identityOf($session);
            $this->sessions[$id->value] = $this->copy($session);
            $this->nextId = max($this->nextId, $id->value + 1);
        }
    }

    public function find(WorkoutSessionId $id): ?WorkoutSession
    {
        $session = $this->sessions[$id->value] ?? null;

        return $session === null ? null : $this->copy($session);
    }

    public function findForUser(WorkoutSessionId $id, UserId $userId): ?WorkoutSession
    {
        $session = $this->find($id);

        if ($session?->userId->value !== $userId->value) {
            return null;
        }

        return $session;
    }

    public function findActiveForUser(UserId $userId): ?WorkoutSession
    {
        foreach ($this->sessions as $session) {
            if (
                $session->userId->value === $userId->value
                && $session->status === WorkoutSessionStatus::InProgress
            ) {
                return $this->copy($session);
            }
        }

        return null;
    }

    public function add(WorkoutSession $session): WorkoutSession
    {
        if ($session->id !== null) {
            throw new LogicException('Нельзя добавить уже сохранённую тренировочную сессию.');
        }

        if ($this->findActiveForUser($session->userId) !== null) {
            throw new ActiveWorkoutSessionAlreadyExists($session->userId);
        }

        $persistedSession = $this->restoreWithIdentity(
            $session,
            new WorkoutSessionId($this->nextId++),
        );
        $id = $this->identityOf($persistedSession);
        $this->sessions[$id->value] = $this->copy($persistedSession);
        $this->addCalls++;

        return $this->copy($persistedSession);
    }

    public function save(WorkoutSession $session): void
    {
        $id = $this->identityOf($session);
        $this->sessions[$id->value] = $this->copy($session);
        $this->saveCalls++;
    }

    private function copy(WorkoutSession $session): WorkoutSession
    {
        return $this->restoreWithIdentity($session, $this->identityOf($session));
    }

    private function restoreWithIdentity(
        WorkoutSession $session,
        WorkoutSessionId $id,
    ): WorkoutSession {
        $exercises = array_map(
            static fn (WorkoutExercise $exercise): WorkoutExercise => WorkoutExercise::restore(
                $exercise->snapshot,
                $exercise->plannedPrescription,
                new WorkoutSetCollection(...$exercise->workoutSets()),
                $exercise->status,
            ),
            $session->workoutExercises(),
        );

        return WorkoutSession::restore(
            $id,
            $session->userId,
            $session->programSnapshot,
            new WorkoutExerciseCollection($exercises[0], ...array_slice($exercises, 1)),
            $session->status,
            $session->startedAt,
            $session->completedAt,
            $session->cancelledAt,
        );
    }

    private function identityOf(WorkoutSession $session): WorkoutSessionId
    {
        return $session->id
            ?? throw new LogicException('У сохранённой тренировочной сессии должен быть идентификатор.');
    }
}
