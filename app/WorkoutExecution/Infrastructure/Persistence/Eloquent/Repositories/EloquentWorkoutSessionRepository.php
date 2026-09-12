<?php

namespace App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Repositories;

use App\WorkoutExecution\Domain\Entities\WorkoutExercise;
use App\WorkoutExecution\Domain\Entities\WorkoutSession;
use App\WorkoutExecution\Domain\Exceptions\ActiveWorkoutSessionAlreadyExists;
use App\WorkoutExecution\Domain\Repositories\WorkoutSessionRepository;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSet;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Mappers\WorkoutSessionMapper;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutExerciseModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSessionModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSetModel;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use LogicException;

final readonly class EloquentWorkoutSessionRepository implements WorkoutSessionRepository
{
    public function __construct(
        private WorkoutSessionMapper $mapper,
        private DatabaseManager $database,
    ) {}

    public function findForUser(WorkoutSessionId $id, UserId $userId): ?WorkoutSession
    {
        return $this->database->transaction(function () use ($id, $userId): ?WorkoutSession {
            $model = WorkoutSessionModel::query()
                ->whereKey($id->value)
                ->where('user_id', $userId->value)
                ->sharedLock()
                ->first();

            return $this->loadAggregate($model);
        });
    }

    public function findActiveForUser(UserId $userId): ?WorkoutSession
    {
        return $this->database->transaction(function () use ($userId): ?WorkoutSession {
            $model = WorkoutSessionModel::query()
                ->where('user_id', $userId->value)
                ->where('status', 'in_progress')
                ->sharedLock()
                ->first();

            return $this->loadAggregate($model);
        });
    }

    public function add(WorkoutSession $session): WorkoutSession
    {
        if ($session->id !== null) {
            throw new LogicException('Нельзя добавить уже сохранённую тренировочную сессию.');
        }

        try {
            $model = $this->database->transaction(function () use ($session): WorkoutSessionModel {
                $model = WorkoutSessionModel::query()->create([
                    'user_id' => $session->userId->value,
                    'training_program_id' => $session->programSnapshot->trainingProgramId->value,
                    'training_program_name' => $session->programSnapshot->name->value,
                    'scheduled_weekday' => $session->programSnapshot->scheduledWeekday->value,
                    'status' => $session->status->value,
                    'started_at' => $this->toUtc($session->startedAt),
                    'completed_at' => $this->nullableToUtc($session->completedAt),
                    'cancelled_at' => $this->nullableToUtc($session->cancelledAt),
                ]);

                foreach ($session->workoutExercises() as $exercise) {
                    $exerciseModel = $model->workoutExercises()->create(
                        $this->exerciseAttributes($exercise),
                    );
                    $exerciseModel->workoutSets()->createMany(
                        $this->setAttributes($exercise),
                    );
                }

                return $model->load('workoutExercises.workoutSets');
            });
        } catch (QueryException $exception) {
            if (! $this->isActiveSessionConflict($exception)) {
                throw $exception;
            }

            throw new ActiveWorkoutSessionAlreadyExists($session->userId);
        }

        return $this->mapper->toDomain($model);
    }

    public function save(WorkoutSession $session): void
    {
        $id = $session->id
            ?? throw new LogicException('У сохранённой тренировочной сессии должен быть идентификатор.');

        $this->database->transaction(function () use ($id, $session): void {
            $model = WorkoutSessionModel::query()
                ->whereKey($id->value)
                ->where('user_id', $session->userId->value)
                ->lockForUpdate()
                ->first();

            if ($model === null) {
                throw new LogicException('Нельзя сохранить несуществующую тренировочную сессию.');
            }

            $this->assertProgramSnapshotUnchanged($model, $session);

            /** @var Collection<int, WorkoutExerciseModel> $exerciseModels */
            $exerciseModels = $model->workoutExercises()->get()->keyBy('exercise_id');
            $exercises = $session->workoutExercises();

            if ($exerciseModels->count() !== count($exercises)) {
                throw new LogicException('Нельзя изменить состав упражнений сохранённой тренировочной сессии.');
            }

            foreach ($exercises as $exercise) {
                /** @var WorkoutExerciseModel|null $exerciseModel */
                $exerciseModel = $exerciseModels->get($exercise->snapshot->exerciseId->value);

                if ($exerciseModel === null) {
                    throw new LogicException('Нельзя изменить состав упражнений сохранённой тренировочной сессии.');
                }

                $this->assertExerciseSnapshotUnchanged($exerciseModel, $exercise);
            }

            $model->update([
                'status' => $session->status->value,
                'completed_at' => $this->nullableToUtc($session->completedAt),
                'cancelled_at' => $this->nullableToUtc($session->cancelledAt),
            ]);

            WorkoutSetModel::query()
                ->whereIn('workout_exercise_id', $exerciseModels->modelKeys())
                ->delete();

            foreach ($exercises as $exercise) {
                /** @var WorkoutExerciseModel $exerciseModel */
                $exerciseModel = $exerciseModels->get($exercise->snapshot->exerciseId->value);
                $exerciseModel->update(['status' => $exercise->status->value]);
                $exerciseModel->workoutSets()->createMany($this->setAttributes($exercise));
            }
        });
    }

    private function loadAggregate(?WorkoutSessionModel $model): ?WorkoutSession
    {
        if ($model === null) {
            return null;
        }

        $model->load('workoutExercises.workoutSets');

        return $this->mapper->toDomain($model);
    }

    /**
     * @return array{
     *     exercise_id: int,
     *     exercise_name: string,
     *     position: int,
     *     planned_sets: int,
     *     planned_repetitions_per_set: int,
     *     planned_working_weight_grams: int,
     *     status: string
     * }
     */
    private function exerciseAttributes(WorkoutExercise $exercise): array
    {
        return [
            'exercise_id' => $exercise->snapshot->exerciseId->value,
            'exercise_name' => $exercise->snapshot->name->value,
            'position' => $exercise->snapshot->position->value,
            'planned_sets' => $exercise->plannedPrescription->setsCount->value,
            'planned_repetitions_per_set' => $exercise->plannedPrescription->repetitionsPerSet->value,
            'planned_working_weight_grams' => $exercise->plannedPrescription->workingWeight->grams,
            'status' => $exercise->status->value,
        ];
    }

    /**
     * @return list<array{
     *     position: int,
     *     repetitions: int,
     *     working_weight_grams: int
     * }>
     */
    private function setAttributes(WorkoutExercise $exercise): array
    {
        return array_map(
            static fn (WorkoutSet $set): array => [
                'position' => $set->position->value,
                'repetitions' => $set->repetitions->value,
                'working_weight_grams' => $set->workingWeight->grams,
            ],
            $exercise->workoutSets(),
        );
    }

    private function assertProgramSnapshotUnchanged(
        WorkoutSessionModel $model,
        WorkoutSession $session,
    ): void {
        if (
            $model->training_program_id !== $session->programSnapshot->trainingProgramId->value
            || $model->training_program_name !== $session->programSnapshot->name->value
            || $model->scheduled_weekday !== $session->programSnapshot->scheduledWeekday->value
            || $model->started_at != $this->toUtc($session->startedAt)
        ) {
            throw new LogicException('Нельзя изменить снимок программы сохранённой тренировочной сессии.');
        }
    }

    private function assertExerciseSnapshotUnchanged(
        WorkoutExerciseModel $model,
        WorkoutExercise $exercise,
    ): void {
        if (
            $model->exercise_name !== $exercise->snapshot->name->value
            || $model->position !== $exercise->snapshot->position->value
            || $model->planned_sets !== $exercise->plannedPrescription->setsCount->value
            || $model->planned_repetitions_per_set !== $exercise->plannedPrescription->repetitionsPerSet->value
            || $model->planned_working_weight_grams !== $exercise->plannedPrescription->workingWeight->grams
        ) {
            throw new LogicException('Нельзя изменить снимок упражнения сохранённой тренировочной сессии.');
        }
    }

    private function toUtc(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    private function nullableToUtc(?DateTimeImmutable $date): ?DateTimeImmutable
    {
        return $date === null ? null : $this->toUtc($date);
    }

    private function isActiveSessionConflict(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'workout_sessions_user_active_unique')
            || str_contains($message, 'workout_sessions.user_id');
    }
}
