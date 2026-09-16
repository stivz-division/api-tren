<?php

namespace App\WorkoutAnalysis\Application\DTO;

use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisAttempt;
use DateTimeImmutable;

final readonly class AnalysisAttemptDTO
{
    public function __construct(
        public private(set) int $number,
        public private(set) int $cycleAttempt,
        public private(set) string $status,
        public private(set) DateTimeImmutable $scheduledAt,
        public private(set) ?DateTimeImmutable $startedAt,
        public private(set) ?DateTimeImmutable $expiresAt,
        public private(set) ?DateTimeImmutable $finishedAt,
        public private(set) ?string $failureCode,
    ) {}

    public static function fromDomain(AnalysisAttempt $value): self
    {
        return new self($value->number, $value->cycleAttempt, $value->status->value, $value->scheduledAt, $value->startedAt, $value->expiresAt, $value->finishedAt, $value->failureCode?->value);
    }
}
