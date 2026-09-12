<?php

namespace App\WorkoutExecution\Application\Factories;

use App\WorkoutExecution\Application\DTO\PlannedExerciseSnapshotData;
use App\WorkoutExecution\Application\DTO\TrainingProgramSnapshotData;
use App\WorkoutExecution\Application\Exceptions\InvalidTrainingProgramSnapshot;
use App\WorkoutExecution\Domain\Collections\WorkoutExerciseCollection;
use App\WorkoutExecution\Domain\Entities\WorkoutExercise;
use App\WorkoutExecution\Domain\Entities\WorkoutSession;
use App\WorkoutExecution\Domain\Enums\ScheduledWeekday;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseName;
use App\WorkoutExecution\Domain\ValueObjects\ExercisePosition;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\PlannedPrescription;
use App\WorkoutExecution\Domain\ValueObjects\ProgramName;
use App\WorkoutExecution\Domain\ValueObjects\Repetitions;
use App\WorkoutExecution\Domain\ValueObjects\SetsCount;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutExecution\Domain\ValueObjects\TrainingProgramSnapshot;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Domain\ValueObjects\WorkingWeight;
use DateTimeImmutable;

final readonly class WorkoutSessionFactory
{
    public function create(
        UserId $userId,
        TrainingProgramSnapshotData $program,
        DateTimeImmutable $startedAt,
    ): WorkoutSession {
        if ($program->exercises === []) {
            throw new InvalidTrainingProgramSnapshot;
        }

        $exercises = array_map(
            static fn (PlannedExerciseSnapshotData $exercise): WorkoutExercise => WorkoutExercise::fromPlan(
                new ExerciseSnapshot(
                    new ExerciseId($exercise->exerciseId),
                    new ExerciseName($exercise->name),
                    new ExercisePosition($exercise->position),
                ),
                new PlannedPrescription(
                    new SetsCount($exercise->sets),
                    new Repetitions($exercise->repetitionsPerSet),
                    new WorkingWeight($exercise->workingWeightInGrams),
                ),
            ),
            $program->exercises,
        );

        return WorkoutSession::start(
            $userId,
            new TrainingProgramSnapshot(
                new TrainingProgramId($program->trainingProgramId),
                new ProgramName($program->name),
                ScheduledWeekday::fromValue($program->scheduledWeekday),
            ),
            new WorkoutExerciseCollection($exercises[0], ...array_slice($exercises, 1)),
            $startedAt,
        );
    }
}
