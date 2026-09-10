<?php

namespace Tests\Support\WorkoutPlanning;

use App\WorkoutPlanning\Domain\Collections\PlannedExerciseCollection;
use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\Exceptions\TrainingProgramAlreadyExists;
use App\WorkoutPlanning\Domain\Repositories\TrainingProgramRepository;
use App\WorkoutPlanning\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use InvalidArgumentException;
use LogicException;

final class InMemoryTrainingProgramRepository implements TrainingProgramRepository
{
    /** @var array<int, TrainingProgram> */
    private array $trainingPrograms = [];

    public private(set) int $saveCalls = 0;

    public private(set) int $addCalls = 0;

    public private(set) int $deleteCalls = 0;

    public function __construct(
        private int $nextId = 1,
        TrainingProgram ...$trainingPrograms,
    ) {
        if ($this->nextId < 1) {
            throw new InvalidArgumentException(
                'Следующий идентификатор программы тренировок должен быть положительным.',
            );
        }

        foreach ($trainingPrograms as $trainingProgram) {
            $id = $this->identityOf($trainingProgram);

            if (isset($this->trainingPrograms[$id->value])) {
                throw new LogicException(
                    'Тестовый репозиторий не может содержать повторяющийся идентификатор программы тренировок.',
                );
            }

            if ($this->existsForUserOnWeekday($trainingProgram->userId, $trainingProgram->weekday)) {
                throw new TrainingProgramAlreadyExists(
                    $trainingProgram->userId,
                    $trainingProgram->weekday,
                );
            }

            $this->trainingPrograms[$id->value] = $this->copy($trainingProgram);
            $this->nextId = max($this->nextId, $id->value + 1);
        }
    }

    public function find(TrainingProgramId $id): ?TrainingProgram
    {
        $trainingProgram = $this->trainingPrograms[$id->value] ?? null;

        return $trainingProgram === null ? null : $this->copy($trainingProgram);
    }

    public function findForUser(TrainingProgramId $id, UserId $userId): ?TrainingProgram
    {
        $trainingProgram = $this->find($id);

        if ($trainingProgram?->userId->equals($userId) !== true) {
            return null;
        }

        return $trainingProgram;
    }

    public function findForUserOnWeekday(UserId $userId, Weekday $weekday): ?TrainingProgram
    {
        foreach ($this->trainingPrograms as $trainingProgram) {
            if ($trainingProgram->userId->equals($userId) && $trainingProgram->weekday === $weekday) {
                return $this->copy($trainingProgram);
            }
        }

        return null;
    }

    public function existsForUserOnWeekday(UserId $userId, Weekday $weekday): bool
    {
        return $this->findForUserOnWeekday($userId, $weekday) !== null;
    }

    public function save(TrainingProgram $trainingProgram): void
    {
        $id = $this->identityOf($trainingProgram);
        $this->trainingPrograms[$id->value] = $this->copy($trainingProgram);
        $this->saveCalls++;
    }

    public function add(TrainingProgram $trainingProgram): TrainingProgram
    {
        if ($trainingProgram->id !== null) {
            throw new LogicException('Нельзя добавить уже сохранённую программу тренировок.');
        }

        if ($this->existsForUserOnWeekday($trainingProgram->userId, $trainingProgram->weekday)) {
            throw new TrainingProgramAlreadyExists(
                $trainingProgram->userId,
                $trainingProgram->weekday,
            );
        }

        $persistedTrainingProgram = $this->restoreWithIdentity(
            $trainingProgram,
            new TrainingProgramId($this->nextId++),
        );
        $persistedId = $this->identityOf($persistedTrainingProgram);
        $this->trainingPrograms[$persistedId->value] = $this->copy($persistedTrainingProgram);
        $this->addCalls++;

        return $this->copy($persistedTrainingProgram);
    }

    public function delete(TrainingProgram $trainingProgram): void
    {
        unset($this->trainingPrograms[$this->identityOf($trainingProgram)->value]);
        $this->deleteCalls++;
    }

    private function copy(TrainingProgram $trainingProgram): TrainingProgram
    {
        return $this->restoreWithIdentity(
            $trainingProgram,
            $this->identityOf($trainingProgram),
        );
    }

    private function restoreWithIdentity(
        TrainingProgram $trainingProgram,
        TrainingProgramId $id,
    ): TrainingProgram {
        $exercises = $trainingProgram->plannedExercises();

        return TrainingProgram::restore(
            $id,
            $trainingProgram->userId,
            $trainingProgram->weekday,
            new PlannedExerciseCollection($exercises[0], ...array_slice($exercises, 1)),
            $trainingProgram->name,
        );
    }

    private function identityOf(TrainingProgram $trainingProgram): TrainingProgramId
    {
        return $trainingProgram->id
            ?? throw new LogicException('У сохранённой программы тренировок должен быть идентификатор.');
    }
}
