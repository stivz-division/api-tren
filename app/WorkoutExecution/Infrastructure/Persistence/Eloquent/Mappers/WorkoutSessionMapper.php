<?php

namespace App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Mappers;

use App\WorkoutExecution\Domain\Collections\WorkoutExerciseCollection;
use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\Entities\WorkoutExercise;
use App\WorkoutExecution\Domain\Entities\WorkoutSession;
use App\WorkoutExecution\Domain\Enums\ScheduledWeekday;
use App\WorkoutExecution\Domain\Enums\WorkoutExerciseStatus;
use App\WorkoutExecution\Domain\Enums\WorkoutSessionStatus;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseName;
use App\WorkoutExecution\Domain\ValueObjects\ExercisePosition;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\ProgramName;
use App\WorkoutExecution\Domain\ValueObjects\Repetitions;
use App\WorkoutExecution\Domain\ValueObjects\SetPosition;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Domain\ValueObjects\WorkingWeight;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSet;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutExerciseModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutPlannedSetModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSessionModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSetModel;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

final readonly class WorkoutSessionMapper
{
    public function toDomain(WorkoutSessionModel $model): WorkoutSession
    {
        if (! $model->relationLoaded('workoutExercises')) {
            throw new LogicException(
                'Для восстановления тренировочной сессии необходимо загрузить упражнения.',
            );
        }

        /** @var list<WorkoutExercise> $exercises */
        $exercises = $model->workoutExercises
            ->map(function (WorkoutExerciseModel $exercise): WorkoutExercise {
                if (! $exercise->relationLoaded('workoutSets')) {
                    throw new LogicException(
                        'Для восстановления упражнения необходимо загрузить фактические подходы.',
                    );
                }

                if (! $exercise->relationLoaded('plannedSets')) {
                    throw new LogicException(
                        'Для восстановления упражнения необходимо загрузить запланированные подходы.',
                    );
                }

                $plannedSets = $exercise->plannedSets
                    ->map(static fn (WorkoutPlannedSetModel $set): WorkoutSet => new WorkoutSet(
                        new SetPosition($set->position),
                        new Repetitions($set->repetitions),
                        new WorkingWeight($set->working_weight_grams),
                    ))
                    ->values()
                    ->all();

                if ($plannedSets === []) {
                    throw new LogicException(
                        'Сохранённое упражнение должно содержать запланированные подходы.',
                    );
                }

                $sets = $exercise->workoutSets
                    ->map(static fn (WorkoutSetModel $set): WorkoutSet => new WorkoutSet(
                        new SetPosition($set->position),
                        new Repetitions($set->repetitions),
                        new WorkingWeight($set->working_weight_grams),
                    ))
                    ->values()
                    ->all();

                return WorkoutExercise::restore(
                    new ExerciseSnapshot(
                        new ExerciseId($exercise->exercise_id),
                        new ExerciseName($exercise->exercise_name),
                        new ExercisePosition($exercise->position),
                    ),
                    new WorkoutSetCollection(...$plannedSets),
                    new WorkoutSetCollection(...$sets),
                    WorkoutExerciseStatus::from($exercise->status),
                );
            })
            ->values()
            ->all();

        if ($exercises === []) {
            throw new LogicException(
                'Сохранённая тренировочная сессия должна содержать хотя бы одно упражнение.',
            );
        }

        return WorkoutSession::restore(
            new WorkoutSessionId($model->id),
            new UserId($model->user_id),
            new TrainingProgramSnapshot(
                new TrainingProgramId($model->training_program_id),
                new ProgramName($model->training_program_name),
                ScheduledWeekday::fromValue($model->scheduled_weekday),
            ),
            new WorkoutExerciseCollection(
                $exercises[0],
                ...array_slice($exercises, 1),
            ),
            WorkoutSessionStatus::from($model->status),
            $this->toUtc($model->started_at),
            $model->completed_at === null ? null : $this->toUtc($model->completed_at),
            $model->cancelled_at === null ? null : $this->toUtc($model->cancelled_at),
        );
    }

    private function toUtc(CarbonImmutable $date): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
