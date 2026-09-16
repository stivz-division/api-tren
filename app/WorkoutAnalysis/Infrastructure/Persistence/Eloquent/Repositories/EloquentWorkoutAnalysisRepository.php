<?php

namespace App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Repositories;

use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\Entities\WorkoutDeviationAnalysis;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisAttempt;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\AnalysisPayload;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\CompletedWorkoutSnapshotCodec;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\WorkoutAnalysisMapper;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\WorkoutDeviationResultCodec;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutAnalysisModel;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\DatabaseManager;
use LogicException;

final readonly class EloquentWorkoutAnalysisRepository implements WorkoutAnalysisRepository
{
    public function __construct(
        private WorkoutAnalysisMapper $mapper,
        private CompletedWorkoutSnapshotCodec $snapshotCodec,
        private WorkoutDeviationResultCodec $resultCodec,
        private DatabaseManager $database,
    ) {}

    public function findForUser(WorkoutAnalysisId $id, UserId $userId): ?WorkoutAnalysis
    {
        return $this->find('id', $id->value, $userId);
    }

    public function findForSession(WorkoutSessionId $sessionId, UserId $userId): ?WorkoutAnalysis
    {
        return $this->find('workout_session_id', $sessionId->value, $userId);
    }

    public function add(WorkoutAnalysis $analysis): WorkoutAnalysis
    {
        $this->assertTransaction();
        if ($analysis->id !== null) {
            throw new LogicException('Нельзя добавить уже сохранённый анализ тренировки.');
        }

        return $this->database->transaction(function () use ($analysis): WorkoutAnalysis {
            $deviations = $analysis->deviations();
            $snapshot = $deviations->snapshot;
            $model = WorkoutAnalysisModel::query()->create([
                'user_id' => $snapshot->userId->value,
                'workout_session_id' => $snapshot->workoutSessionId->value,
                'snapshot' => $this->snapshotCodec->encode($snapshot),
                'snapshot_version' => CompletedWorkoutSnapshotCodec::VERSION,
            ]);
            $stage = $model->deviations()->create($this->stageAttributes($deviations));
            $stage->attempts()->createMany(array_map($this->attemptAttributes(...), $deviations->attempts()));

            return $this->mapper->toDomain($model->load('deviations.attempts'));
        });
    }

    public function save(WorkoutAnalysis $analysis): void
    {
        $this->assertTransaction();
        $id = $analysis->id ?? throw new LogicException('Нельзя сохранить анализ без идентификатора.');
        $deviations = $analysis->deviations();

        $this->database->transaction(function () use ($id, $deviations): void {
            $model = WorkoutAnalysisModel::query()->whereKey($id->value)
                ->where('user_id', $deviations->snapshot->userId->value)->lockForUpdate()->first()
                ?? throw new LogicException('Нельзя сохранить несуществующий анализ тренировки.');
            $model->load('deviations.attempts');
            $persisted = $this->mapper->toDomain($model)->deviations();
            if (! AnalysisPayload::equals($model->snapshot, $this->snapshotCodec->encode($deviations->snapshot))) {
                throw new LogicException('Нельзя изменить сохранённый снимок тренировки.');
            }
            $this->assertHistoryUnchanged($persisted, $deviations);
            $stage = $model->deviations ?? throw new LogicException('Отсутствует этап сравнения тренировки.');
            $attemptModels = $stage->attempts->keyBy('number');
            foreach ($deviations->attempts() as $attempt) {
                $attemptModel = $attemptModels->get($attempt->number);
                if ($attemptModel === null) {
                    $stage->attempts()->create($this->attemptAttributes($attempt));
                } elseif ($attemptModel->finished_at === null) {
                    $attemptModel->fill($this->attemptAttributes($attempt));
                    if ($attemptModel->isDirty()) {
                        $attemptModel->save();
                    }
                }
            }
            $stage->update($this->stageAttributes($deviations));
        });
    }

    private function find(string $column, int $value, UserId $userId): ?WorkoutAnalysis
    {
        return $this->database->transaction(function () use ($column, $value, $userId): ?WorkoutAnalysis {
            $model = WorkoutAnalysisModel::query()->where($column, $value)->where('user_id', $userId->value)
                ->sharedLock()->first();

            return $model === null ? null : $this->mapper->toDomain($model->load('deviations.attempts'));
        });
    }

    private function assertTransaction(): void
    {
        if ($this->database->connection()->transactionLevel() === 0) {
            throw new LogicException('Сохранение анализа тренировки должно выполняться внутри AnalysisTransaction.');
        }
    }

    private function assertHistoryUnchanged(WorkoutDeviationAnalysis $persisted, WorkoutDeviationAnalysis $incoming): void
    {
        $attempts = $incoming->attempts();
        foreach ($persisted->attempts() as $index => $previous) {
            $attempt = $attempts[$index] ?? throw new LogicException('Нельзя удалить сохранённые попытки анализа.');
            if ($previous->finishedAt !== null && $previous != $attempt) {
                throw new LogicException('Нельзя изменить завершённую попытку анализа.');
            }
            if ($previous->number !== $attempt->number || $previous->cycleAttempt !== $attempt->cycleAttempt
                || $previous->scheduledAt != $attempt->scheduledAt
                || ($previous->status === AnalysisStatus::Processing && (
                    $attempt->status === AnalysisStatus::Pending || $previous->startedAt != $attempt->startedAt
                    || $previous->expiresAt != $attempt->expiresAt
                ))) {
                throw new LogicException('Нельзя заменить сохранённую попытку анализа.');
            }
        }
        if ($persisted->result !== null && $persisted->result != $incoming->result) {
            throw new LogicException('Нельзя изменить результат завершённого анализа.');
        }
    }

    /** @return array{status: string, current_attempt_number: int, scheduled_at: DateTimeImmutable, expires_at: DateTimeImmutable|null, result: array<string, mixed>|null, result_version: int|null} */
    private function stageAttributes(WorkoutDeviationAnalysis $stage): array
    {
        $attempt = $stage->currentAttempt();

        return [
            'status' => $stage->status()->value,
            'current_attempt_number' => $attempt->number,
            'scheduled_at' => $attempt->scheduledAt->setTimezone(new DateTimeZone('UTC')),
            'expires_at' => $this->utc($attempt->expiresAt),
            'result' => $stage->result === null ? null : $this->resultCodec->encode($stage->result),
            'result_version' => $stage->result === null ? null : WorkoutDeviationResultCodec::VERSION,
        ];
    }

    /** @return array{number: int, cycle_attempt: int, status: string, scheduled_at: DateTimeImmutable, started_at: DateTimeImmutable|null, expires_at: DateTimeImmutable|null, finished_at: DateTimeImmutable|null, failure_code: string|null} */
    private function attemptAttributes(AnalysisAttempt $attempt): array
    {
        return [
            'number' => $attempt->number,
            'cycle_attempt' => $attempt->cycleAttempt,
            'status' => $attempt->status->value,
            'scheduled_at' => $attempt->scheduledAt->setTimezone(new DateTimeZone('UTC')),
            'started_at' => $this->utc($attempt->startedAt),
            'expires_at' => $this->utc($attempt->expiresAt),
            'finished_at' => $this->utc($attempt->finishedAt),
            'failure_code' => $attempt->failureCode?->value,
        ];
    }

    private function utc(?DateTimeImmutable $date): ?DateTimeImmutable
    {
        return $date?->setTimezone(new DateTimeZone('UTC'));
    }
}
