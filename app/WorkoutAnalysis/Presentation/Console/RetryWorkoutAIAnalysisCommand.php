<?php

namespace App\WorkoutAnalysis\Presentation\Console;

use App\WorkoutAnalysis\Application\UseCases\RetryWorkoutAIAnalysis\RetryWorkoutAIAnalysis;
use App\WorkoutAnalysis\Application\UseCases\RetryWorkoutAIAnalysis\RetryWorkoutAIAnalysisInput;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisTransition;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutAnalysisModel;
use Illuminate\Console\Command;

final class RetryWorkoutAIAnalysisCommand extends Command
{
    protected $signature = 'workout-analysis:retry-ai {analysisId : Идентификатор анализа}';

    protected $description = 'Повторить неудачный этап заключения ИИ';

    public function handle(RetryWorkoutAIAnalysis $retry): int
    {
        $id = filter_var($this->argument('analysisId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->error('Идентификатор анализа должен быть положительным целым числом.');

            return self::INVALID;
        }
        $analysis = WorkoutAnalysisModel::query()->find($id);
        if ($analysis === null) {
            $this->error('Анализ не найден.');

            return self::FAILURE;
        }
        try {
            $result = $retry->handle(new RetryWorkoutAIAnalysisInput($analysis->user_id, $analysis->id));
        } catch (InvalidAnalysisTransition $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if ($result === null) {
            $this->error('Этап ИИ ещё не создан.');

            return self::FAILURE;
        }
        $this->info("Анализ {$analysis->id}: {$result->status()->value}.");

        return self::SUCCESS;
    }
}
