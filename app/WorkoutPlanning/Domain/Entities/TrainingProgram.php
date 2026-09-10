<?php

namespace App\WorkoutPlanning\Domain\Entities;

use App\WorkoutPlanning\Domain\Collections\PlannedExerciseCollection;
use App\WorkoutPlanning\Domain\Enums\Weekday;
use App\WorkoutPlanning\Domain\ValueObjects\ExerciseId;
use App\WorkoutPlanning\Domain\ValueObjects\ProgramName;
use App\WorkoutPlanning\Domain\ValueObjects\RepetitionsPerSet;
use App\WorkoutPlanning\Domain\ValueObjects\SetsCount;
use App\WorkoutPlanning\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use App\WorkoutPlanning\Domain\ValueObjects\WorkingWeight;

final class TrainingProgram
{
    private PlannedExerciseCollection $exercises;

    private function __construct(
        public private(set) readonly ?TrainingProgramId $id,
        public private(set) readonly UserId $userId,
        public private(set) readonly Weekday $weekday,
        public private(set) ProgramName $name,
        PlannedExerciseCollection $exercises,
    ) {
        $this->exercises = $exercises->copy();
    }

    public static function create(
        UserId $userId,
        Weekday $weekday,
        PlannedExerciseCollection $exercises,
        ?ProgramName $name = null,
    ): self {
        return new self(
            null,
            $userId,
            $weekday,
            $name ?? ProgramName::default(),
            $exercises,
        );
    }

    public static function restore(
        TrainingProgramId $id,
        UserId $userId,
        Weekday $weekday,
        PlannedExerciseCollection $exercises,
        ProgramName $name,
    ): self {
        return new self(
            $id,
            $userId,
            $weekday,
            $name,
            $exercises,
        );
    }

    /** @return list<PlannedExercise> */
    public function plannedExercises(): array
    {
        return $this->exercises->copy()->all();
    }

    public function rename(ProgramName $name): void
    {
        $this->name = $name;
    }

    public function replaceExercises(PlannedExerciseCollection $exercises): void
    {
        $this->exercises = $exercises->copy();
    }

    public function addExercise(
        ExerciseId $exerciseId,
        SetsCount $setsCount,
        RepetitionsPerSet $repetitionsPerSet,
        WorkingWeight $workingWeight,
    ): void {
        $this->exercises->add(new PlannedExercise(
            $exerciseId,
            $setsCount,
            $repetitionsPerSet,
            $workingWeight,
            $this->exercises->nextPosition(),
        ));
    }

    public function changeExercisePrescription(
        ExerciseId $exerciseId,
        SetsCount $setsCount,
        RepetitionsPerSet $repetitionsPerSet,
        WorkingWeight $workingWeight,
    ): void {
        $this->exercises->get($exerciseId)->changePrescription(
            $setsCount,
            $repetitionsPerSet,
            $workingWeight,
        );
    }

    public function removeExercise(ExerciseId $exerciseId): void
    {
        $this->exercises->remove($exerciseId);
    }

    public function reorderExercises(ExerciseId ...$exerciseIds): void
    {
        $this->exercises->reorder(...$exerciseIds);
    }
}
