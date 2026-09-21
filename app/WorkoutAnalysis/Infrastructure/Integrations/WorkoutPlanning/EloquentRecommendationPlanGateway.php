<?php

namespace App\WorkoutAnalysis\Infrastructure\Integrations\WorkoutPlanning;

use App\Models\Exercise;
use App\Models\User;
use App\WorkoutAnalysis\Application\Exceptions\RecommendationConflict;
use App\WorkoutAnalysis\Application\Exceptions\RecommendationNotFound;
use App\WorkoutAnalysis\Application\Factories\CompletedWorkoutSnapshotFactory;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\RecommendationPlanGateway;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\Services\RecommendationCounterCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisEvidenceReference;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendation;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Infrastructure\Integrations\WorkoutExecution\CompletedWorkoutDataMapper;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\WorkoutRecommendationMapper;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutRecommendationModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSessionModel;
use App\WorkoutPlanning\Application\Gateways\TrainingProgramMutationLock;
use App\WorkoutPlanning\Domain\ValueObjects\UserId;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\PlannedExerciseModel;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\PlannedSetModel;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\TrainingProgramModel;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use LogicException;

/** @phpstan-import-type ProgramContext from RecommendationPlanGateway */
final readonly class EloquentRecommendationPlanGateway implements RecommendationPlanGateway
{
    public function __construct(
        private DatabaseManager $database,
        private TrainingProgramMutationLock $mutationLock,
        private WorkoutRecommendationMapper $mapper,
        private CompletedWorkoutDataMapper $workoutMapper,
        private CompletedWorkoutSnapshotFactory $snapshotFactory,
        private RecommendationCounterCalculator $counters,
        private AnalysisClock $clock,
        private RecommendationPlanInvalidator $invalidator,
    ) {}

    public function capture(WorkoutAnalysis $analysis): array
    {
        $this->requireTransaction();
        $snapshot = $analysis->deviations()->snapshot;
        $programId = $snapshot->trainingProgramId->value;
        $userId = $snapshot->userId->value;
        $context = ['program_id' => $programId, 'exercises' => [], 'catalog' => []];
        $program = TrainingProgramModel::query()->whereKey($programId)->where('user_id', $userId)->with('plannedExercises.plannedSets')->first();
        if ($program === null || $this->hasNewStart($userId, $programId, $snapshot->workoutSessionId->value)) {
            return $context;
        }
        $history = [];
        $sessions = WorkoutSessionModel::query()->where('user_id', $userId)->where('training_program_id', $programId)
            ->where('status', 'completed')->where(function ($query) use ($snapshot): void {
                $boundary = $snapshot->completedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
                $query->where('completed_at', '<', $boundary)->orWhere(fn ($query) => $query->where('completed_at', $boundary)->where('id', '<=', $snapshot->workoutSessionId->value));
            })
            ->with('workoutExercises.plannedSets', 'workoutExercises.workoutSets')->orderBy('completed_at')->orderBy('id')->get();
        foreach ($sessions as $session) {
            $history[] = $this->snapshotFactory->create($this->workoutMapper->toData($session), $snapshot->userId, new WorkoutSessionId($session->id));
        }
        $events = WorkoutRecommendationModel::query()->where('user_id', $userId)->where('training_program_id', $programId)->where('change_type', 'replacement')->get();
        $lastReplacement = null;
        $rejectedGroups = [];
        foreach ($events as $event) {
            if ($event->applied_at !== null && ($lastReplacement === null || $event->applied_at > $lastReplacement)) {
                $lastReplacement = $event->applied_at;
            }
            if ($event->rejected_at !== null) {
                $first = $rejectedGroups[$event->workout_analysis_id] ?? null;
                if ($first === null || $event->rejected_at < $first) {
                    $rejectedGroups[$event->workout_analysis_id] = $event->rejected_at;
                }
            }
        }
        $lastRejected = $rejectedGroups === [] ? null : max($rejectedGroups);
        foreach ($program->plannedExercises as $exercise) {
            $sets = $this->plannedSets($exercise);
            $barrier = $this->barrier($userId, $programId, $exercise->exercise_id);
            $eligibility = $this->counters->calculate($exercise->exercise_id, $this->mapper->sets($sets), $history,
                $lastReplacement?->toDateTimeImmutable(), $lastRejected?->toDateTimeImmutable(),
                $barrier === null ? null : new DateTimeImmutable($barrier->changed_at));
            $context['exercises'][] = [
                'exercise_id' => $exercise->exercise_id, 'sets' => $sets, 'revision' => $barrier === null ? 0 : (int) $barrier->revision,
                'successes' => $eligibility->successes, 'failures' => $eligibility->failures,
                'completed_since_replacement' => $eligibility->completedSinceReplacement, 'completed_since_rejection' => $eligibility->completedSinceRejection,
                'currently_successful' => $eligibility->currentlySuccessful,
            ];
        }
        $context['catalog'] = array_values(Exercise::query()->orderBy('id')->get(['id', 'name'])->map(static fn (Exercise $exercise): array => ['id' => $exercise->id, 'name' => $exercise->name])->all());

        return $context;
    }

    public function store(WorkoutAnalysis $analysis, array $programContext, array $proposals, DateTimeImmutable $now): void
    {
        $this->requireTransaction();
        $snapshot = $analysis->deviations()->snapshot;
        $targets = array_column($programContext['exercises'], null, 'exercise_id');
        foreach ($proposals as $proposal) {
            $target = $targets[$proposal->exerciseId] ?? throw new LogicException('Предложено упражнение вне зафиксированного плана.');
            $item = new WorkoutRecommendationModel;
            $item->fill([
                'user_id' => $snapshot->userId->value, 'workout_analysis_id' => ($analysis->id ?? throw new LogicException('Анализ не сохранён.'))->value,
                'workout_session_id' => $snapshot->workoutSessionId->value, 'training_program_id' => $programContext['program_id'],
                'exercise_id' => $proposal->exerciseId, 'source_revision' => $target['revision'],
                'replacement_exercise_id' => $proposal->replacementExerciseId, 'change_type' => $proposal->changeType,
                'original_sets' => $target['sets'], 'proposed_sets' => $this->mapper->encodeSets($proposal->proposedSets),
                'rationale' => $proposal->rationale, 'status' => 'proposed', 'source_completed_at' => $snapshot->completedAt,
                'evidence' => array_map(static fn (AnalysisEvidenceReference $reference): array => [
                    'analysis_id' => $reference->analysisId->value, 'workout_session_id' => $reference->workoutSessionId->value, 'exercise_id' => $reference->exerciseId?->value,
                ], $proposal->evidence),
            ]);
            if ($this->currentTarget($item) === null) {
                $item->status = 'expired';
                $item->fill(['expired_at' => $now]);
            }
            $item->save();
        }
    }

    public function recommendations(int $userId, int $analysisId): array
    {
        return array_values(WorkoutRecommendationModel::query()->where('user_id', $userId)->where('workout_analysis_id', $analysisId)->orderBy('id')->get()
            ->map(fn (WorkoutRecommendationModel $model): HistoricalRecommendation => $this->mapper->toDomain($model))->all());
    }

    public function act(int $userId, int $recommendationId, string $action): HistoricalRecommendation
    {
        if (! in_array($action, ['apply', 'reject'], true)) {
            throw new RecommendationConflict;
        }

        return $this->mutationLock->execute(new UserId($userId), fn (): HistoricalRecommendation => $this->database->transaction(function () use ($userId, $recommendationId, $action): HistoricalRecommendation {
            if (User::query()->whereKey($userId)->lockForUpdate()->first() === null) {
                throw new RecommendationNotFound;
            }
            $item = WorkoutRecommendationModel::query()->whereKey($recommendationId)->where('user_id', $userId)->lockForUpdate()->first() ?? throw new RecommendationNotFound;
            $terminal = $action === 'apply' ? 'applied' : 'rejected';
            if ($item->status === $terminal || $item->status === 'expired') {
                return $this->mapper->toDomain($item);
            }
            if ($item->status !== 'proposed') {
                throw new RecommendationConflict;
            }
            $now = $this->clock->now();
            $target = $this->currentTarget($item);
            if ($target === null) {
                $item->update(['status' => 'expired', 'expired_at' => $now]);

                return $this->mapper->toDomain($item);
            }
            $item->update(['status' => $terminal, $action === 'apply' ? 'applied_at' : 'rejected_at' => $now]);
            if ($action === 'apply') {
                if ($item->replacement_exercise_id !== null) {
                    $target->update(['exercise_id' => $item->replacement_exercise_id]);
                }
                $target->plannedSets()->delete();
                $target->plannedSets()->createMany($item->proposed_sets);
                $this->invalidator->changed($item->user_id, $item->training_program_id, $item->exercise_id, $now);
                if ($item->replacement_exercise_id !== null) {
                    $this->invalidator->changed($item->user_id, $item->training_program_id, $item->replacement_exercise_id, $now);
                }
            }

            return $this->mapper->toDomain($item);
        }));
    }

    private function currentTarget(WorkoutRecommendationModel $item): ?PlannedExerciseModel
    {
        if ($this->hasNewStart($item->user_id, $item->training_program_id, $item->workout_session_id)
            || ! TrainingProgramModel::query()->whereKey($item->training_program_id)->where('user_id', $item->user_id)->exists()) {
            return null;
        }
        $barrier = $this->barrier($item->user_id, $item->training_program_id, $item->exercise_id);
        if (($barrier === null ? 0 : (int) $barrier->revision) !== $item->source_revision) {
            return null;
        }
        $target = PlannedExerciseModel::query()->where('training_program_id', $item->training_program_id)->where('exercise_id', $item->exercise_id)->with('plannedSets')->first();
        if ($target === null || $this->plannedSets($target) !== $item->original_sets || ! Exercise::query()->whereKey($item->exercise_id)->exists()) {
            return null;
        }
        if ($item->replacement_exercise_id !== null && (! Exercise::query()->whereKey($item->replacement_exercise_id)->exists()
            || PlannedExerciseModel::query()->where('training_program_id', $item->training_program_id)->where('exercise_id', $item->replacement_exercise_id)->exists())) {
            return null;
        }

        return $target;
    }

    private function requireTransaction(): void
    {
        if ($this->database->connection()->transactionLevel() < 1) {
            throw new LogicException('Контекст рекомендаций требует транзакции пользователя.');
        }
    }

    private function hasNewStart(int $userId, int $programId, int $sourceSessionId): bool
    {
        return WorkoutSessionModel::query()->where('user_id', $userId)->where('training_program_id', $programId)->where('id', '>', $sourceSessionId)->exists();
    }

    /** @return object{revision:int|string,changed_at:string}|null */
    private function barrier(int $userId, int $programId, int $exerciseId): ?object
    {
        /** @var object{revision:int|string,changed_at:string}|null $barrier */
        $barrier = $this->database->table('workout_plan_change_barriers')->where('user_id', $userId)->where('training_program_id', $programId)->where('exercise_id', $exerciseId)->first(['revision', 'changed_at']);

        return $barrier;
    }

    /** @return list<array{position:int,repetitions:int,working_weight_grams:int}> */
    private function plannedSets(PlannedExerciseModel $exercise): array
    {
        return array_values($exercise->plannedSets->map(static fn (PlannedSetModel $set): array => ['position' => $set->position, 'repetitions' => $set->repetitions, 'working_weight_grams' => $set->working_weight_grams])->all());
    }
}
