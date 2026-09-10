<?php

namespace App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Mappers;

use App\WorkoutPlanning\Domain\Collections\PlannedExerciseCollection;
use App\WorkoutPlanning\Domain\Entities\PlannedExercise;
use App\WorkoutPlanning\Domain\Entities\TrainingProgram;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use App\WorkoutPlanning\Domain\ValueObjects\ExercisePosition;
use App\WorkoutPlanning\Domain\ValueObjects\ProgramName;
use App\WorkoutPlanning\Domain\ValueObjects\RepetitionsPerSet;
use App\WorkoutPlanning\Domain\ValueObjects\SetsCount;
use App\WorkoutPlanning\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use App\WorkoutPlanning\Domain\ValueObjects\WorkingWeight;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\PlannedExerciseModel;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\TrainingProgramModel;
use LogicException;

final readonly class TrainingProgramMapper
{
    public function toDomain(TrainingProgramModel $model): TrainingProgram
    {
        if (! $model->relationLoaded('plannedExercises')) {
            throw new LogicException(
                'Для восстановления программы тренировок необходимо загрузить запланированные упражнения.',
            );
        }

        /** @var list<PlannedExercise> $plannedExercises */
        $plannedExercises = $model->plannedExercises
            ->map(static fn (PlannedExerciseModel $exercise): PlannedExercise => new PlannedExercise(
                new ExerciseId($exercise->exercise_id),
                new SetsCount($exercise->sets),
                new RepetitionsPerSet($exercise->repetitions_per_set),
                new WorkingWeight($exercise->working_weight_grams),
                new ExercisePosition($exercise->position),
            ))
            ->values()
            ->all();

        if ($plannedExercises === []) {
            throw new LogicException(
                'Сохранённая программа тренировок должна содержать хотя бы одно упражнение.',
            );
        }

        return TrainingProgram::restore(
            new TrainingProgramId($model->id),
            new UserId($model->user_id),
            Weekday::fromValue($model->weekday),
            new PlannedExerciseCollection(
                $plannedExercises[0],
                ...array_slice($plannedExercises, 1),
            ),
            new ProgramName($model->name),
        );
    }
}
