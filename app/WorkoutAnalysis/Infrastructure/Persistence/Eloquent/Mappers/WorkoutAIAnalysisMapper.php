<?php

namespace App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers;

use App\WorkoutAnalysis\Domain\Entities\WorkoutAIAnalysis;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisAttempt;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutAIAnalysisModel;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutAIAttemptModel;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use UnexpectedValueException;

final readonly class WorkoutAIAnalysisMapper
{
    public function __construct(private WorkoutAIResultCodec $resultCodec) {}

    public function toDomain(WorkoutAIAnalysisModel $model, ?AnalysisContextSnapshot $context): WorkoutAIAnalysis
    {
        if (! $model->relationLoaded('attempts')) {
            throw new LogicException('Попытки ИИ должны быть загружены.');
        }
        if (($model->result === null) !== ($model->result_version === null)) {
            throw new UnexpectedValueException('Версия заключения не согласована с результатом.');
        }
        $result = $model->result === null ? null : $this->resultCodec->decode(
            $model->result,
            $model->result_version ?? throw new UnexpectedValueException('Отсутствует версия заключения.'),
            new WorkoutAnalysisId($model->workout_analysis_id),
            $context ?? throw new UnexpectedValueException('Заключение сохранено без контекста.'),
        );
        $stage = WorkoutAIAnalysis::restore($model->attempts->map(fn (WorkoutAIAttemptModel $attempt): AnalysisAttempt => new AnalysisAttempt(
            $attempt->number, $attempt->cycle_attempt, AnalysisStatus::from($attempt->status),
            $this->utc($attempt->scheduled_at) ?? throw new UnexpectedValueException('Отсутствует время попытки.'),
            $this->utc($attempt->started_at), $this->utc($attempt->expires_at), $this->utc($attempt->finished_at),
            $attempt->failure_code === null ? null : AnalysisFailureCode::from($attempt->failure_code),
        ))->all(), $result);
        $current = $stage->currentAttempt();
        if ($model->status !== $current->status->value || $model->current_attempt_number !== $current->number
            || $this->utc($model->scheduled_at) != $current->scheduledAt || $this->utc($model->expires_at) != $current->expiresAt) {
            throw new UnexpectedValueException('Состояние этапа ИИ не соответствует истории попыток.');
        }

        return $stage;
    }

    private function utc(?DateTimeImmutable $date): ?DateTimeImmutable
    {
        return $date === null ? null : DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'));
    }
}
