<?php

namespace App\WorkoutExecution\Infrastructure\Integrations\WorkoutPlanning;

use App\WorkoutAnalysis\Infrastructure\Integrations\WorkoutPlanning\RecommendationPlanInvalidator;
use App\WorkoutExecution\Application\Exceptions\WorkoutReplacementPlanConflict;
use App\WorkoutExecution\Application\Gateways\WorkoutReplacementPlanGateway;
use App\WorkoutExecution\Domain\Collections\WorkoutSetCollection;
use App\WorkoutExecution\Domain\Entities\WorkoutExercise;
use App\WorkoutExecution\Domain\Entities\WorkoutSession;
use App\WorkoutExecution\Domain\ValueObjects\ExerciseId;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSet;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\PlannedExerciseModel;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\PlannedSetModel;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\TrainingProgramModel;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;

final readonly class EloquentWorkoutReplacementPlanGateway implements WorkoutReplacementPlanGateway
{
    public function __construct(
        private DatabaseManager $database,
        private RecommendationPlanInvalidator $invalidator,
    ) {}

    public function replace(
        WorkoutSession $session,
        WorkoutExercise $exercise,
        ExerciseId $replacementId,
        WorkoutSetCollection $sets,
        DateTimeImmutable $now,
    ): int {
        if ($this->database->connection()->transactionLevel() === 0) {
            throw new LogicException('Замена в плане требует транзакции мутации сессии.');
        }
        $userId = $session->userId->value;
        $programId = $session->programSnapshot->trainingProgramId->value;
        $baselineId = $exercise->planBaselineSnapshot()->exerciseId->value;
        $program = TrainingProgramModel::query()
            ->whereKey($programId)
            ->where('user_id', $userId)
            ->first();
        $target = $program?->plannedExercises()
            ->where('exercise_id', $baselineId)
            ->with('plannedSets')
            ->first();
        if ($target === null) {
            throw new WorkoutReplacementPlanConflict;
        }

        $barrier = $this->barrier($userId, $programId, $baselineId);
        $revision = $barrier === null ? 0 : (int) $barrier->revision;
        $baselineRevision = $exercise->planBaselineRevision();
        $hasDuplicate = $program->plannedExercises()
            ->where('exercise_id', $replacementId->value)
            ->whereKeyNot($target->id)
            ->exists();
        $hasLegacyChange = $baselineRevision === null
            && $barrier !== null
            && new DateTimeImmutable($barrier->changed_at) > $session->startedAt;

        if (
            ($baselineRevision !== null && $baselineRevision !== $revision)
            || $hasLegacyChange
            || $this->storedSets($target) !== $this->setRows(new WorkoutSetCollection(...$exercise->planBaselineSets()))
            || $hasDuplicate
        ) {
            throw new WorkoutReplacementPlanConflict;
        }

        $target->exercise_id = $replacementId->value;
        $target->save();
        $target->plannedSets()->delete();
        $target->plannedSets()->createMany($this->setRows($sets));
        $this->invalidator->changed($userId, $programId, $baselineId, $now);
        if ($replacementId->value !== $baselineId) {
            $this->invalidator->changed($userId, $programId, $replacementId->value, $now);
        }

        $newBarrier = $this->barrier($userId, $programId, $replacementId->value)
            ?? throw new LogicException('Отсутствует версия нового плана.');

        return (int) $newBarrier->revision;
    }

    /** @return object{revision:int|string,changed_at:string}|null */
    private function barrier(int $userId, int $programId, int $exerciseId): ?object
    {
        /** @var object{revision:int|string,changed_at:string}|null $barrier */
        $barrier = $this->database->table('workout_plan_change_barriers')
            ->where('user_id', $userId)
            ->where('training_program_id', $programId)
            ->where('exercise_id', $exerciseId)
            ->first(['revision', 'changed_at']);

        return $barrier;
    }

    /** @return list<array{position:int,repetitions:int,working_weight_grams:int}> */
    private function storedSets(PlannedExerciseModel $exercise): array
    {
        return array_values($exercise->plannedSets->map(static fn (PlannedSetModel $set): array => [
            'position' => $set->position,
            'repetitions' => $set->repetitions,
            'working_weight_grams' => $set->working_weight_grams,
        ])->all());
    }

    /** @return list<array{position:int,repetitions:int,working_weight_grams:int}> */
    private function setRows(WorkoutSetCollection $sets): array
    {
        return array_map(static fn (WorkoutSet $set): array => [
            'position' => $set->position->value,
            'repetitions' => $set->repetitions->value,
            'working_weight_grams' => $set->workingWeight->grams,
        ], $sets->all());
    }
}
