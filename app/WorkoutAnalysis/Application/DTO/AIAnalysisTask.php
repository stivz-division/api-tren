<?php

namespace App\WorkoutAnalysis\Application\DTO;

use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use DateTimeImmutable;
use LogicException;

final readonly class AIAnalysisTask
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
        $stage = $analysis->ai() ?? throw new LogicException('Отсутствует этап ИИ.');
        $attempt = $stage->currentAttempt();

        return new self($id->value, $analysis->deviations()->snapshot->userId->value, $attempt->number, $attempt->scheduledAt);
    }
}
