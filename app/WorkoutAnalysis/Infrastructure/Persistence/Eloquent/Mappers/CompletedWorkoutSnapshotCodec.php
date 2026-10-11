<?php

namespace App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers;

use App\WorkoutAnalysis\Domain\Collections\ExercisePerformanceCollection;
use App\WorkoutAnalysis\Domain\Collections\SetSnapshotCollection;
use App\WorkoutAnalysis\Domain\Enums\ExerciseCompletionStatus;
use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExcludedExerciseSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseName;
use App\WorkoutAnalysis\Domain\ValueObjects\ExercisePerformanceSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExercisePosition;
use App\WorkoutAnalysis\Domain\ValueObjects\ProgramName;
use App\WorkoutAnalysis\Domain\ValueObjects\Repetitions;
use App\WorkoutAnalysis\Domain\ValueObjects\SetPosition;
use App\WorkoutAnalysis\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkingWeight;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSetSnapshot;
use UnexpectedValueException;

final class CompletedWorkoutSnapshotCodec
{
    public const int VERSION = 2;

    /** @return array<string, mixed> */
    public function encode(CompletedWorkoutSnapshot $snapshot, int $version = self::VERSION): array
    {
        $this->assertVersion($version);
        if ($version === 1 && $snapshot->excludedExercises !== []) {
            throw new UnexpectedValueException('Формат v1 не поддерживает исключённые упражнения.');
        }
        $payload = [
            'workout_session_id' => $snapshot->workoutSessionId->value,
            'user_id' => $snapshot->userId->value,
            'training_program_id' => $snapshot->trainingProgramId->value,
            'program_name' => $snapshot->programName->value,
            'completed_at' => AnalysisPayload::formatDate($snapshot->completedAt),
            'exercises' => array_map(fn (ExercisePerformanceSnapshot $exercise): array => [
                'exercise_id' => $exercise->exerciseId->value,
                'name' => $exercise->name->value,
                'position' => $exercise->position->value,
                'status' => $exercise->status->value,
                'planned_sets' => array_map($this->encodeSet(...), $exercise->plannedSets->all()),
                'actual_sets' => array_map($this->encodeSet(...), $exercise->actualSets->all()),
            ], $snapshot->exercises->all()),
        ];
        if ($version === 2) {
            $payload['excluded_exercises'] = array_map(
                static fn (ExcludedExerciseSnapshot $excluded): array => [
                    'source_exercise_id' => $excluded->sourceExerciseId->value,
                    'plan_exercise_id' => $excluded->planExerciseId->value,
                    'position' => $excluded->position->value,
                ],
                $snapshot->excludedExercises,
            );
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function decode(array $payload, int $version): CompletedWorkoutSnapshot
    {
        $this->assertVersion($version);

        $exercises = [];
        foreach (AnalysisPayload::list($payload['exercises'] ?? null) as $item) {
            $exercise = AnalysisPayload::object($item);
            $exercises[] = new ExercisePerformanceSnapshot(
                new ExerciseId(AnalysisPayload::integer($exercise['exercise_id'] ?? null)),
                new ExerciseName(AnalysisPayload::string($exercise['name'] ?? null)),
                new ExercisePosition(AnalysisPayload::integer($exercise['position'] ?? null)),
                ExerciseCompletionStatus::from(AnalysisPayload::string($exercise['status'] ?? null)),
                $this->decodeSets($exercise['planned_sets'] ?? null),
                $this->decodeSets($exercise['actual_sets'] ?? null),
            );
        }

        $excludedExercises = [];
        if ($version === 2) {
            foreach (AnalysisPayload::list($payload['excluded_exercises'] ?? null) as $item) {
                $excluded = AnalysisPayload::object($item);
                $excludedExercises[] = new ExcludedExerciseSnapshot(
                    new ExerciseId(AnalysisPayload::integer($excluded['source_exercise_id'] ?? null)),
                    new ExerciseId(AnalysisPayload::integer($excluded['plan_exercise_id'] ?? null)),
                    new ExercisePosition(AnalysisPayload::integer($excluded['position'] ?? null)),
                );
            }
        }

        $snapshot = new CompletedWorkoutSnapshot(
            new WorkoutSessionId(AnalysisPayload::integer($payload['workout_session_id'] ?? null)),
            new UserId(AnalysisPayload::integer($payload['user_id'] ?? null)),
            new TrainingProgramId(AnalysisPayload::integer($payload['training_program_id'] ?? null)),
            new ProgramName(AnalysisPayload::string($payload['program_name'] ?? null)),
            AnalysisPayload::date($payload['completed_at'] ?? null),
            new ExercisePerformanceCollection(...$exercises),
            $excludedExercises,
        );

        if (! AnalysisPayload::equals($this->encode($snapshot, $version), $payload)) {
            throw new UnexpectedValueException('Некорректные данные снимка тренировки.');
        }

        return $snapshot;
    }

    private function assertVersion(int $version): void
    {
        if (! in_array($version, [1, self::VERSION], true)) {
            throw new UnexpectedValueException('Неподдерживаемая версия формата снимка тренировки.');
        }
    }

    /** @return array{position: int, repetitions: int, working_weight_grams: int} */
    public function encodeSet(WorkoutSetSnapshot $set): array
    {
        return [
            'position' => $set->position->value,
            'repetitions' => $set->repetitions->value,
            'working_weight_grams' => $set->workingWeight->grams,
        ];
    }

    public function decodeSets(mixed $payload): SetSnapshotCollection
    {
        $sets = [];
        foreach (AnalysisPayload::list($payload) as $item) {
            $set = AnalysisPayload::object($item);
            $sets[] = new WorkoutSetSnapshot(
                new SetPosition(AnalysisPayload::integer($set['position'] ?? null)),
                new Repetitions(AnalysisPayload::integer($set['repetitions'] ?? null)),
                new WorkingWeight(AnalysisPayload::integer($set['working_weight_grams'] ?? null)),
            );
        }

        return new SetSnapshotCollection(...$sets);
    }
}
