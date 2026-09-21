<?php

namespace App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers;

use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\Entities\WorkoutDeviationAnalysis;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisAttempt;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutAnalysisModel;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutDeviationAttemptModel;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use UnexpectedValueException;

final readonly class WorkoutAnalysisMapper
{
    public function __construct(
        private CompletedWorkoutSnapshotCodec $snapshotCodec,
        private WorkoutDeviationResultCodec $resultCodec,
        private AnalysisContextSnapshotCodec $contextCodec,
        private WorkoutAIAnalysisMapper $aiMapper,
    ) {}

    public function toDomain(WorkoutAnalysisModel $model): WorkoutAnalysis
    {
        if (! $model->relationLoaded('deviations') || $model->deviations === null || ! $model->deviations->relationLoaded('attempts')) {
            throw new LogicException('Этап анализа тренировки и его попытки должны быть загружены.');
        }

        $snapshot = $this->snapshotCodec->decode($model->snapshot, $model->snapshot_version);
        if ($snapshot->userId->value !== $model->user_id || $snapshot->workoutSessionId->value !== $model->workout_session_id) {
            throw new UnexpectedValueException('Идентификаторы анализа тренировки не соответствуют его снимку.');
        }

        $stage = $model->deviations;
        if (($stage->result === null) !== ($stage->result_version === null)) {
            throw new UnexpectedValueException('Версия формата результата сравнения не согласована с наличием результата.');
        }

        $result = $stage->result === null ? null : $this->resultCodec->decode(
            $stage->result,
            $stage->result_version ?? throw new UnexpectedValueException('Отсутствует версия формата результата сравнения тренировки.'),
            $snapshot,
        );
        $attempts = $stage->attempts->map(fn (WorkoutDeviationAttemptModel $attempt): AnalysisAttempt => $this->attemptToDomain($attempt))->all();
        $deviations = WorkoutDeviationAnalysis::restore($snapshot, $attempts, $result);
        $current = $deviations->currentAttempt();
        if ($stage->status !== $current->status->value || $stage->current_attempt_number !== $current->number
            || $this->toUtc($stage->scheduled_at) != $current->scheduledAt
            || $this->toUtc($stage->expires_at) != $current->expiresAt) {
            throw new UnexpectedValueException('Состояние этапа сравнения не соответствует истории попыток.');
        }

        if (($model->context === null) !== ($model->context_version === null)) {
            throw new UnexpectedValueException('Версия контекста не согласована с наличием снимка.');
        }
        $context = $model->context === null ? null : $this->contextCodec->decode(
            $model->context,
            $model->context_version ?? throw new UnexpectedValueException('Отсутствует версия контекста.'),
            $result ?? throw new UnexpectedValueException('Контекст сохранён без готового результата сравнения.'),
        );

        if (! $model->relationLoaded('ai')) {
            throw new LogicException('Этап ИИ должен быть загружен.');
        }

        return WorkoutAnalysis::restore(new WorkoutAnalysisId($model->id), $deviations, $context,
            $model->ai === null ? null : $this->aiMapper->toDomain($model->ai, $context));
    }

    public function attemptToDomain(WorkoutDeviationAttemptModel $model): AnalysisAttempt
    {
        return new AnalysisAttempt(
            $model->number,
            $model->cycle_attempt,
            AnalysisStatus::from($model->status),
            $this->toUtc($model->scheduled_at) ?? throw new UnexpectedValueException('Отсутствует запланированное время запуска попытки.'),
            $this->toUtc($model->started_at),
            $this->toUtc($model->expires_at),
            $this->toUtc($model->finished_at),
            $model->failure_code === null ? null : AnalysisFailureCode::from($model->failure_code),
        );
    }

    private function toUtc(?DateTimeImmutable $date): ?DateTimeImmutable
    {
        return $date === null ? null : DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'));
    }
}
