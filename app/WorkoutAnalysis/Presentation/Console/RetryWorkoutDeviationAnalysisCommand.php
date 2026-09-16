<?php

namespace App\WorkoutAnalysis\Presentation\Console;

use App\WorkoutAnalysis\Application\UseCases\RetryWorkoutDeviationAnalysis\RetryWorkoutDeviationAnalysis;
use App\WorkoutAnalysis\Application\UseCases\RetryWorkoutDeviationAnalysis\RetryWorkoutDeviationAnalysisInput;
use App\WorkoutAnalysis\Domain\Exceptions\InvalidAnalysisTransition;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutAnalysisModel;
use Illuminate\Console\Command;

final class RetryWorkoutDeviationAnalysisCommand extends Command
{
    protected $signature = 'workout-analysis:retry {analysisId : Идентификатор анализа}';

    protected $description = 'Повторить неудачный этап сравнения тренировки';

    public function handle(RetryWorkoutDeviationAnalysis $retry): int
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
            $result = $retry->handle(new RetryWorkoutDeviationAnalysisInput($analysis->user_id, $analysis->id));
        } catch (InvalidAnalysisTransition $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info("Анализ {$result->id}: {$result->status}.");

        return self::SUCCESS;
    }
}
