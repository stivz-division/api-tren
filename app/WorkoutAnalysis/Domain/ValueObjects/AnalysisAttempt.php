<?php

namespace App\WorkoutAnalysis\Domain\ValueObjects;

use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AnalysisAttempt
{
    public function __construct(
        public private(set) int $number,
        public private(set) int $cycleAttempt,
        public private(set) AnalysisStatus $status,
        public private(set) DateTimeImmutable $scheduledAt,
        public private(set) ?DateTimeImmutable $startedAt = null,
        public private(set) ?DateTimeImmutable $expiresAt = null,
        public private(set) ?DateTimeImmutable $finishedAt = null,
        public private(set) ?AnalysisFailureCode $failureCode = null,
    ) {
        if ($number < 1 || $cycleAttempt < 1 || $cycleAttempt > $number) {
            throw new InvalidArgumentException('Некорректный номер попытки анализа.');
        }

        $started = $startedAt !== null && $expiresAt !== null
            && $startedAt >= $scheduledAt && $expiresAt > $startedAt;
        $finished = $started && $finishedAt !== null && $finishedAt >= $startedAt;

        $valid = match ($status) {
            AnalysisStatus::Pending => $startedAt === null && $expiresAt === null
                && $finishedAt === null && $failureCode === null,
            AnalysisStatus::Processing => $started && $finishedAt === null && $failureCode === null,
            AnalysisStatus::Completed => $finished && $finishedAt < $expiresAt && $failureCode === null,
            AnalysisStatus::Failed => $finished && $failureCode !== null,
        };

        if (! $valid) {
            throw new InvalidArgumentException('Некорректное состояние попытки анализа.');
        }
    }

    public function start(DateTimeImmutable $now, DateTimeImmutable $expiresAt): self
    {
        if ($this->status !== AnalysisStatus::Pending) {
            throw new InvalidArgumentException('Начать можно только ожидающую попытку.');
        }

        return new self($this->number, $this->cycleAttempt, AnalysisStatus::Processing, $this->scheduledAt, $now, $expiresAt);
    }

    public function finish(DateTimeImmutable $now, ?AnalysisFailureCode $failure = null): self
    {
        if ($this->status !== AnalysisStatus::Processing) {
            throw new InvalidArgumentException('Завершить можно только выполняющуюся попытку.');
        }

        return new self(
            $this->number,
            $this->cycleAttempt,
            $failure === null ? AnalysisStatus::Completed : AnalysisStatus::Failed,
            $this->scheduledAt,
            $this->startedAt,
            $this->expiresAt,
            $now,
            $failure,
        );
    }
}
