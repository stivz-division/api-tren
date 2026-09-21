<?php

namespace App\WorkoutAnalysis\Infrastructure\Integrations\WorkoutExecution;

use App\WorkoutAnalysis\Application\DTO\HistoricalWorkoutData;
use App\WorkoutAnalysis\Application\DTO\WorkoutHistoryData;
use App\WorkoutAnalysis\Application\DTO\WorkoutHistoryQuery;
use App\WorkoutAnalysis\Application\Gateways\WorkoutHistoryProvider;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendationResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\CompletedWorkoutSnapshotCodec;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\RecommendationBatchCodec;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\WorkoutAIResultCodec;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\WorkoutDeviationResultCodec;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\WorkoutRecommendationMapper;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutAnalysisModel;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutRecommendationModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSessionModel;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use LogicException;
use UnexpectedValueException;

final readonly class EloquentWorkoutHistoryProvider implements WorkoutHistoryProvider
{
    public function __construct(
        private DatabaseManager $database,
        private CompletedWorkoutDataMapper $workouts,
        private CompletedWorkoutSnapshotCodec $snapshotCodec,
        private WorkoutDeviationResultCodec $resultCodec,
        private WorkoutAIResultCodec $aiResultCodec,
        private WorkoutRecommendationMapper $recommendationMapper,
        private RecommendationBatchCodec $recommendationCodec,
    ) {}

    public function read(WorkoutHistoryQuery $query): WorkoutHistoryData
    {
        if ($this->database->connection()->transactionLevel() === 0) {
            throw new LogicException('История должна читаться внутри AnalysisTransaction.');
        }
        if ($query->sameProgramLimit < 1 || $query->otherProgramsLimit < 1) {
            throw new LogicException('Лимиты истории должны быть положительными.');
        }

        return new WorkoutHistoryData(
            $this->window($query, true, $query->sameProgramLimit),
            $this->window($query, false, $query->otherProgramsLimit),
        );
    }

    /** @return list<HistoricalWorkoutData> */
    private function window(WorkoutHistoryQuery $query, bool $sameProgram, int $limit): array
    {
        $completedBefore = $query->completedBefore->setTimezone(new DateTimeZone('UTC'));
        $dateFormat = $completedBefore->format('u') === '000000' ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u';
        $sessions = WorkoutSessionModel::query()->where('user_id', $query->userId)
            ->where('status', 'completed')->where('id', '<>', $query->currentWorkoutSessionId)
            ->where('training_program_id', $sameProgram ? '=' : '<>', $query->trainingProgramId)
            ->where('completed_at', '<', $completedBefore->format($dateFormat))
            ->orderByDesc('completed_at')->orderByDesc('id')->limit($limit)
            ->with('workoutExercises.plannedSets', 'workoutExercises.workoutSets')->get();
        if ($sessions->isEmpty()) {
            return [];
        }
        $analyses = WorkoutAnalysisModel::query()->select(['id', 'user_id', 'workout_session_id', 'snapshot', 'snapshot_version'])
            ->where('user_id', $query->userId)->whereIn('workout_session_id', $sessions->modelKeys())
            ->with('deviations', 'ai', 'recommendations:id,workout_analysis_id,status,result,result_version')->get()->keyBy('workout_session_id');
        $recommendations = WorkoutRecommendationModel::query()
            ->where('user_id', $query->userId)->whereIn('workout_analysis_id', $analyses->modelKeys())
            ->orderBy('id')->get()->groupBy('workout_analysis_id');

        $entries = [];
        foreach ($sessions as $session) {
            $analysis = $analyses->get($session->id);
            $result = null;
            $conclusion = null;
            if ($analysis !== null) {
                $stage = $analysis->deviations ?? throw new UnexpectedValueException('Отсутствует этап сравнения исторического анализа.');
                if ($stage->status === AnalysisStatus::Completed->value) {
                    $snapshot = $this->snapshotCodec->decode($analysis->snapshot, $analysis->snapshot_version);
                    if ($snapshot->userId->value !== $query->userId || $snapshot->workoutSessionId->value !== $session->id) {
                        throw new UnexpectedValueException('Снимок исторического анализа относится к другой тренировке.');
                    }
                    $result = $this->resultCodec->decode(
                        $stage->result ?? throw new UnexpectedValueException('Отсутствует готовый результат исторического анализа.'),
                        $stage->result_version ?? throw new UnexpectedValueException('Отсутствует версия исторического результата.'),
                        $snapshot,
                    );
                }
            }
            if ($analysis?->ai?->status === AnalysisStatus::Completed->value) {
                $conclusion = $this->aiResultCodec->decodeConclusion(
                    $analysis->ai->result ?? throw new UnexpectedValueException('Отсутствует готовое заключение.'),
                    $analysis->ai->result_version ?? throw new UnexpectedValueException('Отсутствует версия заключения.'),
                    new WorkoutAnalysisId($analysis->id), new WorkoutSessionId($session->id),
                );
            }
            $recommendationResult = null;
            if ($analysis?->recommendations?->status === AnalysisStatus::Completed->value) {
                $generation = $analysis->recommendations;
                $batch = $this->recommendationCodec->decode(
                    $generation->result ?? throw new UnexpectedValueException('Отсутствует готовый результат рекомендаций.'),
                    $generation->result_version ?? throw new UnexpectedValueException('Отсутствует версия рекомендаций.'),
                );
                $items = $recommendations->get($analysis->id);
                if (count($batch->proposals) !== ($items?->count() ?? 0)) {
                    throw new UnexpectedValueException('Сохранённые рекомендации не соответствуют результату генерации.');
                }
                $recommendationResult = new HistoricalRecommendationResult(
                    new WorkoutAnalysisId($analysis->id), new WorkoutSessionId($session->id),
                    ...($items === null ? [] : $items->map($this->recommendationMapper->toDomain(...))->all()),
                );
            }
            $entries[] = new HistoricalWorkoutData($this->workouts->toData($session), $result, $conclusion, $recommendationResult);
        }

        return $entries;
    }
}
