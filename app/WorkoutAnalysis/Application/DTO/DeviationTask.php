<?php

namespace App\WorkoutAnalysis\Application\DTO;

use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use DateTimeImmutable;
use LogicException;

final readonly class DeviationTask
{
    public function __construct(
        public private(set) int $analysisId,
        public private(set) int $userId,
        public private(set) int $attemptNumber,
        public private(set) DateTimeImmutable $availableAt,
    ) {}

    public static function fromDomain(WorkoutAnalysis $analysis): self
    {
        $id = $analysis->id ?? throw new LogicException('Нельзя запланировать несохранённый анализ.');
        $stage = $analysis->deviations();
        $attempt = $stage->currentAttempt();

        return new self($id->value, $stage->snapshot->userId->value, $attempt->number, $attempt->scheduledAt);
    }
}
