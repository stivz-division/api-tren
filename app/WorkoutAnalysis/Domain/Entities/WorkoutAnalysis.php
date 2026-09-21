<?php

namespace App\WorkoutAnalysis\Domain\Entities;

use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisContext;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisTransition;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationBatch;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAIResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutAnalysisId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutDeviationResult;
use DateTimeImmutable;

/** @phpstan-type ProgramContext array{program_id:int,exercises:list<array{exercise_id:int,sets:list<array{position:int,repetitions:int,working_weight_grams:int}>,revision:int,successes:int,failures:int,completed_since_replacement:int,completed_since_rejection:int,currently_successful:bool}>,catalog:list<array{id:int,name:string}>} */
final class WorkoutAnalysis
{
    private ?AnalysisContextSnapshot $context = null;

    private ?WorkoutAIAnalysis $ai = null;

    private ?WorkoutRecommendationGeneration $recommendations = null;

    /** @var ProgramContext|null */
    private ?array $recommendationContext = null;

    private function __construct(
        public private(set) readonly ?WorkoutAnalysisId $id,
        private WorkoutDeviationAnalysis $deviations,
    ) {}

    public static function initialize(CompletedWorkoutSnapshot $snapshot, DateTimeImmutable $now): self
    {
        return new self(null, WorkoutDeviationAnalysis::pending($snapshot, $now));
    }

    /** @param ProgramContext|null $recommendationContext */
    public static function restore(WorkoutAnalysisId $id, WorkoutDeviationAnalysis $deviations, ?AnalysisContextSnapshot $context = null, ?WorkoutAIAnalysis $ai = null, ?WorkoutRecommendationGeneration $recommendations = null, ?array $recommendationContext = null): self
    {
        $analysis = new self($id, clone $deviations);
        if ($context !== null) {
            $analysis->attachContext($context);
        }

        if ($ai !== null) {
            $finishedAt = $deviations->currentAttempt()->finishedAt;
            if ($deviations->status() !== AnalysisStatus::Completed || $finishedAt === null || $ai->attempts()[0]->scheduledAt < $finishedAt) {
                throw new InvalidAnalysisTransition;
            }
            if ($ai->result !== null && ($ai->result->context != $context || $ai->result->conclusion->analysisId != $id)) {
                throw new InvalidAnalysisContext('Заключение не соответствует анализу или его контексту.');
            }
            $analysis->ai = clone $ai;
        }

        if ($recommendationContext !== null) {
            $analysis->attachRecommendationContext($recommendationContext);
        }
        if ($recommendations !== null) {
            if ($ai?->status() !== AnalysisStatus::Completed || $recommendations->attempts()[0]->scheduledAt < $ai->currentAttempt()->finishedAt) {
                throw new InvalidAnalysisTransition;
            }
            if ($recommendations->programContext != $recommendationContext) {
                throw new InvalidAnalysisContext('Контекст рекомендаций не соответствует снимку программы.');
            }
            $analysis->recommendations = clone $recommendations;
        }

        return $analysis;
    }

    public function deviations(): WorkoutDeviationAnalysis
    {
        return clone $this->deviations;
    }

    public function context(): ?AnalysisContextSnapshot
    {
        return $this->context;
    }

    public function attachContext(AnalysisContextSnapshot $context): bool
    {
        $finishedAt = $this->deviations->currentAttempt()->finishedAt;
        if (
            $this->deviations->status() !== AnalysisStatus::Completed
            || $this->deviations->result != $context->currentWorkout
            || $finishedAt === null
            || $context->capturedAt < $finishedAt
        ) {
            throw new InvalidAnalysisContext('Контекст должен соответствовать готовым отклонениям текущего анализа.');
        }

        if ($this->context !== null) {
            if ($this->context != $context) {
                throw new InvalidAnalysisContext('Зафиксированный контекст анализа нельзя заменить.');
            }

            return false;
        }

        $this->context = $context;

        return true;
    }

    public function startDeviationAttempt(int $number, DateTimeImmutable $now, DateTimeImmutable $expiresAt): bool
    {
        return $this->deviations->start($number, $now, $expiresAt);
    }

    public function completeDeviationAttempt(int $number, WorkoutDeviationResult $result, DateTimeImmutable $now): bool
    {
        return $this->deviations->complete($number, $result, $now);
    }

    public function failDeviationAttempt(int $number, AnalysisFailureCode $failure, DateTimeImmutable $now, ?DateTimeImmutable $retryAt): bool
    {
        return $this->deviations->fail($number, $failure, $now, $retryAt);
    }

    public function retryDeviations(DateTimeImmutable $now): bool
    {
        return $this->deviations->retry($now);
    }

    public function recoverExpiredDeviationAttempt(DateTimeImmutable $now, ?DateTimeImmutable $retryAt): bool
    {
        return $this->deviations->recoverExpired($now, $retryAt);
    }

    public function ai(): ?WorkoutAIAnalysis
    {
        return $this->ai === null ? null : clone $this->ai;
    }

    public function scheduleAI(DateTimeImmutable $now): bool
    {
        if ($this->ai !== null) {
            return false;
        }
        if ($this->deviations->status() !== AnalysisStatus::Completed || $now < $this->deviations->currentAttempt()->finishedAt) {
            throw new InvalidAnalysisTransition;
        }
        $this->ai = WorkoutAIAnalysis::pending($now);

        return true;
    }

    public function startAIAttempt(int $number, DateTimeImmutable $now, DateTimeImmutable $expiresAt): bool
    {
        return $this->ai?->start($number, $now, $expiresAt) ?? false;
    }

    public function completeAIAttempt(int $number, WorkoutAIResult $result, DateTimeImmutable $now): bool
    {
        if ($result->context != $this->context || $result->conclusion->analysisId != $this->id) {
            throw new InvalidAnalysisContext('Заключение не соответствует зафиксированному контексту анализа.');
        }

        return $this->ai?->complete($number, $result, $now) ?? false;
    }

    public function failAIAttempt(int $number, AnalysisFailureCode $failure, DateTimeImmutable $now, ?DateTimeImmutable $retryAt): bool
    {
        return $this->ai?->fail($number, $failure, $now, $retryAt) ?? false;
    }

    public function retryAI(DateTimeImmutable $now): bool
    {
        return $this->ai?->retry($now) ?? false;
    }

    public function recoverExpiredAIAttempt(DateTimeImmutable $now, ?DateTimeImmutable $retryAt): bool
    {
        return $this->ai?->recoverExpired($now, $retryAt) ?? false;
    }

    /** @return ProgramContext|null */
    public function recommendationContext(): ?array
    {
        return $this->recommendationContext;
    }

    /** @param ProgramContext $context */
    public function attachRecommendationContext(array $context): void
    {
        if ($this->recommendationContext !== null && $this->recommendationContext != $context) {
            throw new InvalidAnalysisContext('Зафиксированный контекст программы нельзя заменить.');
        }
        $this->recommendationContext = $context;
    }

    public function recommendations(): ?WorkoutRecommendationGeneration
    {
        return $this->recommendations === null ? null : clone $this->recommendations;
    }

    public function scheduleRecommendations(DateTimeImmutable $now): bool
    {
        if ($this->recommendations !== null) {
            return false;
        }
        if ($this->ai?->status() !== AnalysisStatus::Completed || $now < $this->ai->currentAttempt()->finishedAt) {
            throw new InvalidAnalysisTransition;
        }
        $this->recommendations = WorkoutRecommendationGeneration::pending($now);
        if ($this->recommendationContext !== null) {
            $this->recommendations->captureProgramContext($this->recommendationContext);
        }

        return true;
    }

    public function startRecommendationAttempt(int $number, DateTimeImmutable $now, DateTimeImmutable $expiresAt): bool
    {
        return $this->recommendations?->start($number, $now, $expiresAt) ?? false;
    }

    public function completeRecommendationAttempt(int $number, RecommendationBatch $result, DateTimeImmutable $now): bool
    {
        return $this->recommendations?->complete($number, $result, $now) ?? false;
    }

    /** @param list<string> $rejectedReasons */
    public function failRecommendationAttempt(int $number, AnalysisFailureCode $failure, DateTimeImmutable $now, ?DateTimeImmutable $retryAt, array $rejectedReasons = []): bool
    {
        return $this->recommendations?->fail($number, $failure, $now, $retryAt, $rejectedReasons) ?? false;
    }

    public function retryRecommendations(DateTimeImmutable $now): bool
    {
        return $this->recommendations?->retry($now) ?? false;
    }

    public function recoverExpiredRecommendationAttempt(DateTimeImmutable $now, ?DateTimeImmutable $retryAt): bool
    {
        return $this->recommendations?->recoverExpired($now, $retryAt) ?? false;
    }

    public function overallStatus(): AnalysisStatus
    {
        $statuses = [$this->deviations->status(), $this->ai?->status(), $this->recommendations?->status()];
        if (in_array(AnalysisStatus::Failed, $statuses, true)) {
            return AnalysisStatus::Failed;
        }
        if (in_array(AnalysisStatus::Processing, $statuses, true)) {
            return AnalysisStatus::Processing;
        }

        return count(array_filter($statuses, static fn (?AnalysisStatus $status): bool => $status === AnalysisStatus::Completed)) === 3
            ? AnalysisStatus::Completed : AnalysisStatus::Pending;
    }

    public function __clone(): void
    {
        $this->deviations = clone $this->deviations;
        $this->ai = $this->ai === null ? null : clone $this->ai;
        $this->recommendations = $this->recommendations === null ? null : clone $this->recommendations;
    }
}
