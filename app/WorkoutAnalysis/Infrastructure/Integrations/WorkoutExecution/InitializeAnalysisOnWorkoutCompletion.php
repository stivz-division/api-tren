<?php

namespace App\WorkoutAnalysis\Infrastructure\Integrations\WorkoutExecution;

use App\WorkoutAnalysis\Application\UseCases\InitializeWorkoutAnalysis\InitializeWorkoutAnalysis;
use App\WorkoutAnalysis\Application\UseCases\InitializeWorkoutAnalysis\InitializeWorkoutAnalysisInput;
use App\WorkoutExecution\Application\Gateways\WorkoutCompletionNotifier;
use App\WorkoutExecution\Domain\ValueObjects\UserId;
use App\WorkoutExecution\Domain\ValueObjects\WorkoutSessionId;

final readonly class InitializeAnalysisOnWorkoutCompletion implements WorkoutCompletionNotifier
{
    public function __construct(private InitializeWorkoutAnalysis $initialize) {}

    public function completed(WorkoutSessionId $sessionId, UserId $userId): void
    {
        $this->initialize->handle(new InitializeWorkoutAnalysisInput($userId->value, $sessionId->value));
    }
}
