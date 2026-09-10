<?php

namespace App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Repositories;

use App\WorkoutPlanning\Domain\Entities\PlannedExercise;
use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\Exceptions\TrainingProgramAlreadyExists;
use App\WorkoutPlanning\Domain\Repositories\TrainingProgramRepository;
use App\WorkoutPlanning\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Mappers\TrainingProgramMapper;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\TrainingProgramModel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use LogicException;

final readonly class EloquentTrainingProgramRepository implements TrainingProgramRepository
{
    public function __construct(
        private TrainingProgramMapper $mapper,
        private DatabaseManager $database,
    ) {}

    public function findForUser(TrainingProgramId $id, UserId $userId): ?TrainingProgram
    {
        $model = $this->queryWithExercises()
            ->whereKey($id->value)
            ->where('user_id', $userId->value)
            ->first();

        return $model === null ? null : $this->mapper->toDomain($model);
    }

    public function findForUserOnWeekday(UserId $userId, Weekday $weekday): ?TrainingProgram
    {
        $model = $this->queryWithExercises()
            ->where('user_id', $userId->value)
            ->where('weekday', $weekday->value)
            ->first();

        return $model === null ? null : $this->mapper->toDomain($model);
    }

    public function add(TrainingProgram $trainingProgram): TrainingProgram
    {
        if ($trainingProgram->id !== null) {
            throw new LogicException('Нельзя добавить уже сохранённую программу тренировок.');
        }

        try {
            $model = $this->database->transaction(function () use ($trainingProgram): TrainingProgramModel {
                $model = TrainingProgramModel::query()->create([
                    'user_id' => $trainingProgram->userId->value,
                    'weekday' => $trainingProgram->weekday->value,
                    'name' => $trainingProgram->name->value,
                ]);

                $model->plannedExercises()->createMany(
                    $this->plannedExerciseAttributes($trainingProgram),
                );

                return $model->load('plannedExercises');
            });
        } catch (QueryException $exception) {
            if (! $this->isProgramSlotConflict($exception)) {
                throw $exception;
            }

            throw new TrainingProgramAlreadyExists(
                $trainingProgram->userId,
                $trainingProgram->weekday,
            );
        }

        return $this->mapper->toDomain($model);
    }

    public function save(TrainingProgram $trainingProgram): void
    {
        $id = $this->identityOf($trainingProgram);

        $this->database->transaction(function () use ($id, $trainingProgram): void {
            $model = TrainingProgramModel::query()
                ->whereKey($id->value)
                ->where('user_id', $trainingProgram->userId->value)
                ->lockForUpdate()
                ->first();

            if ($model === null) {
                throw new LogicException('Нельзя сохранить несуществующую программу тренировок.');
            }

            $model->update([
                'weekday' => $trainingProgram->weekday->value,
                'name' => $trainingProgram->name->value,
            ]);
            $model->plannedExercises()->delete();
            $model->plannedExercises()->createMany(
                $this->plannedExerciseAttributes($trainingProgram),
            );
        });
    }

    public function delete(TrainingProgram $trainingProgram): void
    {
        $id = $this->identityOf($trainingProgram);
        $deletedRows = TrainingProgramModel::query()
            ->whereKey($id->value)
            ->where('user_id', $trainingProgram->userId->value)
            ->delete();

        if ($deletedRows !== 1) {
            throw new LogicException('Нельзя удалить несуществующую программу тренировок.');
        }
    }

    /** @return Builder<TrainingProgramModel> */
    private function queryWithExercises(): Builder
    {
        return TrainingProgramModel::query()->with('plannedExercises');
    }

    /**
     * @return list<array{
     *     exercise_id: int,
     *     sets: int,
     *     repetitions_per_set: int,
     *     working_weight_grams: int,
     *     position: int
     * }>
     */
    private function plannedExerciseAttributes(TrainingProgram $trainingProgram): array
    {
        return array_map(
            static fn (PlannedExercise $exercise): array => [
                'exercise_id' => $exercise->exerciseId->value,
                'sets' => $exercise->setsCount->value,
                'repetitions_per_set' => $exercise->repetitionsPerSet->value,
                'working_weight_grams' => $exercise->workingWeight->grams,
                'position' => $exercise->position->value,
            ],
            $trainingProgram->plannedExercises(),
        );
    }

    private function identityOf(TrainingProgram $trainingProgram): TrainingProgramId
    {
        return $trainingProgram->id
            ?? throw new LogicException('У сохранённой программы тренировок должен быть идентификатор.');
    }

    private function isProgramSlotConflict(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'training_programs_user_weekday_unique')
            || str_contains($message, 'training_programs.user_id, training_programs.weekday');
    }
}
