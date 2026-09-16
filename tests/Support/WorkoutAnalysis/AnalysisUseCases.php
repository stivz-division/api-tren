<?php

namespace Tests\Support\WorkoutAnalysis;

use App\WorkoutAnalysis\Application\Factories\CompletedWorkoutSnapshotFactory;
use App\WorkoutAnalysis\Application\Policies\AnalysisExecutionPolicy;
use App\WorkoutAnalysis\Application\UseCases\CalculateWorkoutDeviations\CalculateWorkoutDeviations;
use App\WorkoutAnalysis\Application\UseCases\GetWorkoutDeviationAnalysis\GetWorkoutDeviationAnalysis;
use App\WorkoutAnalysis\Application\UseCases\InitializeWorkoutAnalysis\InitializeWorkoutAnalysis;
use App\WorkoutAnalysis\Application\UseCases\RecordWorkoutDeviationFailure\RecordWorkoutDeviationFailure;
use App\WorkoutAnalysis\Application\UseCases\RecoverWorkoutAnalysis\RecoverWorkoutAnalysis;
use App\WorkoutAnalysis\Application\UseCases\RetryWorkoutDeviationAnalysis\RetryWorkoutDeviationAnalysis;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;

final readonly class AnalysisUseCases
{
    public function __construct(public InMemoryAnalysisEnvironment $env, public AnalysisExecutionPolicy $policy = new AnalysisExecutionPolicy) {}

    public function initialize(): InitializeWorkoutAnalysis
    {
        return new InitializeWorkoutAnalysis($this->env, $this->env->provider(), new CompletedWorkoutSnapshotFactory, $this->env, $this->env, $this->env);
    }

    public function failures(): RecordWorkoutDeviationFailure
    {
        return new RecordWorkoutDeviationFailure($this->env, $this->env, $this->env, $this->env, $this->policy);
    }

    public function calculate(): CalculateWorkoutDeviations
    {
        return new CalculateWorkoutDeviations($this->env, new WorkoutDeviationCalculator, $this->env, $this->env, $this->policy, $this->failures());
    }

    public function retry(): RetryWorkoutDeviationAnalysis
    {
        return new RetryWorkoutDeviationAnalysis($this->env, $this->env, $this->env, $this->env);
    }

    public function recover(): RecoverWorkoutAnalysis
    {
        return new RecoverWorkoutAnalysis($this->env, $this->env, $this->env, $this->env, $this->policy);
    }

    public function get(): GetWorkoutDeviationAnalysis
    {
        return new GetWorkoutDeviationAnalysis($this->env);
    }
}
