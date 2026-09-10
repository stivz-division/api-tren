<?php

namespace App\WorkoutPlanning\Domain\Repositories;

use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\Exceptions\TrainingProgramAlreadyExists;
use App\WorkoutPlanning\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use LogicException;

interface TrainingProgramRepository
{
    public function findForUser(TrainingProgramId $id, UserId $userId): ?TrainingProgram;

    public function findForUserOnWeekday(UserId $userId, Weekday $weekday): ?TrainingProgram;

    /**
     * Атомарно сохраняет новый агрегат без идентификатора и возвращает агрегат
     * с идентификатором, сгенерированным хранилищем.
     * При конфликте уникальности агрегат не должен быть сохранён даже частично.
     *
     * @throws TrainingProgramAlreadyExists
     * @throws LogicException Если агрегат уже имеет идентификатор
     */
    public function add(TrainingProgram $trainingProgram): TrainingProgram;

    /**
     * @throws LogicException Если агрегат ещё не имеет идентификатора
     */
    public function save(TrainingProgram $trainingProgram): void;

    /**
     * @throws LogicException Если агрегат ещё не имеет идентификатора
     */
    public function delete(TrainingProgram $trainingProgram): void;
}
