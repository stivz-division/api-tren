<?php

namespace App\WorkoutAnalysis\Infrastructure\Providers;

use App\WorkoutAnalysis\Application\Gateways\AIAnalysisTaskScheduler;
use App\WorkoutAnalysis\Application\Gateways\AIProvider;
use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTaskScheduler;
use App\WorkoutAnalysis\Application\Gateways\AnalysisTransaction;
use App\WorkoutAnalysis\Application\Gateways\CompletedWorkoutProvider;
use App\WorkoutAnalysis\Application\Gateways\RecommendationPlanGateway;
use App\WorkoutAnalysis\Application\Gateways\RecommendationProvider;
use App\WorkoutAnalysis\Application\Gateways\RecommendationTaskScheduler;
use App\WorkoutAnalysis\Application\Gateways\WorkoutHistoryProvider;
use App\WorkoutAnalysis\Application\Policies\AIExecutionPolicy;
use App\WorkoutAnalysis\Application\Policies\AnalysisExecutionPolicy;
use App\WorkoutAnalysis\Application\Policies\AnalysisHistoryPolicy;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Infrastructure\Integrations\OpenAI\OpenAIAnalysisProvider;
use App\WorkoutAnalysis\Infrastructure\Integrations\OpenAI\OpenAIRecommendationProvider;
use App\WorkoutAnalysis\Infrastructure\Integrations\WorkoutExecution\EloquentCompletedWorkoutProvider;
use App\WorkoutAnalysis\Infrastructure\Integrations\WorkoutExecution\EloquentWorkoutHistoryProvider;
use App\WorkoutAnalysis\Infrastructure\Integrations\WorkoutExecution\InitializeAnalysisOnWorkoutCompletion;
use App\WorkoutAnalysis\Infrastructure\Integrations\WorkoutPlanning\EloquentRecommendationPlanGateway;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Repositories\EloquentWorkoutAnalysisRepository;
use App\WorkoutAnalysis\Infrastructure\Queue\LaravelAIAnalysisTaskScheduler;
use App\WorkoutAnalysis\Infrastructure\Queue\LaravelAnalysisTaskScheduler;
use App\WorkoutAnalysis\Infrastructure\Queue\LaravelRecommendationTaskScheduler;
use App\WorkoutAnalysis\Infrastructure\Time\UtcAnalysisClock;
use App\WorkoutAnalysis\Infrastructure\Transactions\DatabaseAnalysisTransaction;
use App\WorkoutAnalysis\Presentation\Console\RecoverWorkoutAnalysisCommand;
use App\WorkoutAnalysis\Presentation\Console\RetryWorkoutAIAnalysisCommand;
use App\WorkoutAnalysis\Presentation\Console\RetryWorkoutDeviationAnalysisCommand;
use App\WorkoutAnalysis\Presentation\Console\RetryWorkoutRecommendationsCommand;
use App\WorkoutExecution\Application\Gateways\WorkoutCompletionNotifier;
use Illuminate\Support\ServiceProvider;

final class WorkoutAnalysisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RecommendationProvider::class, OpenAIRecommendationProvider::class);
        $this->app->bind(RecommendationPlanGateway::class, EloquentRecommendationPlanGateway::class);
        $this->app->bind(RecommendationTaskScheduler::class, LaravelRecommendationTaskScheduler::class);
        $this->app->bind(AIProvider::class, OpenAIAnalysisProvider::class);
        $this->app->bind(AIAnalysisTaskScheduler::class, LaravelAIAnalysisTaskScheduler::class);
        $this->app->singleton(AIExecutionPolicy::class, static function (): AIExecutionPolicy {
            /** @var array<int, int> $delays */
            $delays = config('workout-analysis.ai.retry_delays_seconds', [5, 30]);

            return new AIExecutionPolicy(
                (int) config('workout-analysis.ai.max_attempts', 3),
                (int) config('workout-analysis.ai.processing_timeout_seconds', 120),
                (int) config('workout-analysis.ai.pending_recovery_delay_seconds', 60),
                $delays,
            );
        });
        $this->app->bind(WorkoutAnalysisRepository::class, EloquentWorkoutAnalysisRepository::class);
        $this->app->bind(CompletedWorkoutProvider::class, EloquentCompletedWorkoutProvider::class);
        $this->app->bind(WorkoutHistoryProvider::class, EloquentWorkoutHistoryProvider::class);
        $this->app->singleton(AnalysisHistoryPolicy::class, static fn (): AnalysisHistoryPolicy => new AnalysisHistoryPolicy(
            (int) config('workout-analysis.history.same_program_limit', 20),
            (int) config('workout-analysis.history.other_programs_limit', 20),
        ));
        $this->app->bind(AnalysisTransaction::class, DatabaseAnalysisTransaction::class);
        $this->app->bind(AnalysisTaskScheduler::class, LaravelAnalysisTaskScheduler::class);
        $this->app->singleton(AnalysisClock::class, UtcAnalysisClock::class);
        $this->app->bind(WorkoutCompletionNotifier::class, InitializeAnalysisOnWorkoutCompletion::class);
        $this->app->singleton(AnalysisExecutionPolicy::class, static function (): AnalysisExecutionPolicy {
            /** @var array<int, int> $delays */
            $delays = config('workout-analysis.execution.retry_delays_seconds', [5, 30]);

            return new AnalysisExecutionPolicy(
                (int) config('workout-analysis.execution.max_attempts', 3),
                (int) config('workout-analysis.execution.processing_timeout_seconds', 120),
                (int) config('workout-analysis.execution.pending_recovery_delay_seconds', 60),
                $delays,
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RecoverWorkoutAnalysisCommand::class, RetryWorkoutDeviationAnalysisCommand::class, RetryWorkoutAIAnalysisCommand::class, RetryWorkoutRecommendationsCommand::class]);
        }
    }
}
