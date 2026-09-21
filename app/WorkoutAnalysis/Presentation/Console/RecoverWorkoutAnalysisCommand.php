<?php

namespace App\WorkoutAnalysis\Presentation\Console;

use App\WorkoutAnalysis\Application\Gateways\AnalysisClock;
use App\WorkoutAnalysis\Application\Policies\AIExecutionPolicy;
use App\WorkoutAnalysis\Application\Policies\AnalysisExecutionPolicy;
use App\WorkoutAnalysis\Application\UseCases\RecoverWorkoutAIAnalysis\RecoverWorkoutAIAnalysis;
use App\WorkoutAnalysis\Application\UseCases\RecoverWorkoutAIAnalysis\RecoverWorkoutAIAnalysisInput;
use App\WorkoutAnalysis\Application\UseCases\RecoverWorkoutAnalysis\RecoverWorkoutAnalysis;
use App\WorkoutAnalysis\Application\UseCases\RecoverWorkoutAnalysis\RecoverWorkoutAnalysisInput;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutAIAnalysisModel;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutDeviationAnalysisModel;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;
use Throwable;

final class RecoverWorkoutAnalysisCommand extends Command
{
    protected $signature = 'workout-analysis:recover';

    protected $description = 'Восстановить ожидающие и зависшие этапы сравнения и заключения ИИ';

    public function handle(RecoverWorkoutAnalysis $recover, AnalysisClock $clock, AnalysisExecutionPolicy $policy, LoggerInterface $logger, RecoverWorkoutAIAnalysis $recoverAI, AIExecutionPolicy $aiPolicy): int
    {
        $now = $clock->now();
        $pendingBefore = $now->modify("-{$policy->pendingRecoveryDelayInSeconds} seconds");
        $failed = 0;
        $processed = 0;
        $batchSize = max(1, (int) config('workout-analysis.recovery.batch_size', 100));
        $limit = max(1, (int) config('workout-analysis.recovery.max_candidates', 1000));
        $candidates = WorkoutDeviationAnalysisModel::query()->with('analysis')
            ->where(function (Builder $query) use ($now, $pendingBefore): void {
                $query->where(function (Builder $pending) use ($pendingBefore): void {
                    $pending->where('status', 'pending')->where('scheduled_at', '<=', $pendingBefore->format('Y-m-d H:i:s.uP'));
                })->orWhere(function (Builder $processing) use ($now): void {
                    $processing->where('status', 'processing')->where('expires_at', '<=', $now->format('Y-m-d H:i:s.uP'));
                });
            })->lazyById($batchSize)->take($limit);

        foreach ($candidates as $stage) {
            $analysis = $stage->analysis;
            if ($analysis === null) {
                continue;
            }
            try {
                $recover->handle(new RecoverWorkoutAnalysisInput($analysis->user_id, $analysis->workout_session_id));
                $processed++;
            } catch (Throwable $exception) {
                $failed++;
                $logger->error('Не удалось восстановить анализ тренировки.', ['analysis_id' => $analysis->id, 'exception' => $exception]);
            }
        }

        $pendingBefore = $now->modify("-{$aiPolicy->pendingRecoveryDelayInSeconds} seconds");
        $aiCandidates = WorkoutAIAnalysisModel::query()->with('analysis')
            ->where(function (Builder $query) use ($now, $pendingBefore): void {
                $query->where(function (Builder $pending) use ($pendingBefore): void {
                    $pending->where('status', 'pending')->where('scheduled_at', '<=', $pendingBefore->format('Y-m-d H:i:s.uP'));
                })->orWhere(function (Builder $processing) use ($now): void {
                    $processing->where('status', 'processing')->where('expires_at', '<=', $now->format('Y-m-d H:i:s.uP'));
                });
            })->lazyById($batchSize)->take($limit);

        foreach ($aiCandidates as $stage) {
            $analysis = $stage->analysis;
            if ($analysis === null) {
                continue;
            }
            try {
                $recoverAI->handle(new RecoverWorkoutAIAnalysisInput($analysis->user_id, $analysis->workout_session_id));
                $processed++;
            } catch (Throwable $exception) {
                $failed++;
                $logger->error('Не удалось восстановить заключение ИИ.', ['analysis_id' => $analysis->id, 'exception' => $exception]);
            }
        }

        $this->info("Обработано: {$processed}. Ошибок: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
