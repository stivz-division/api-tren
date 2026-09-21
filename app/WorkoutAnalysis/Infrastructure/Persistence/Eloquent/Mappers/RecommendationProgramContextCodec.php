<?php

namespace App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers;

use UnexpectedValueException;

/** @phpstan-import-type ProgramContext from \App\WorkoutAnalysis\Domain\Entities\WorkoutRecommendationGeneration */
final class RecommendationProgramContextCodec
{
    public const int VERSION = 1;

    /** @param array<string, mixed> $payload
     * @return ProgramContext
     */
    public function decode(array $payload, int $version): array
    {
        if ($version !== self::VERSION || count($payload) !== 3 || AnalysisPayload::integer($payload['program_id'] ?? null) < 1) {
            throw new UnexpectedValueException('Некорректный контекст программы.');
        }
        $exercises = [];
        $ids = [];
        foreach (AnalysisPayload::list($payload['exercises'] ?? null) as $item) {
            $exercise = AnalysisPayload::object($item);
            if (count($exercise) !== 8 || ! is_bool($exercise['currently_successful'] ?? null)) {
                throw new UnexpectedValueException('Некорректный снимок упражнения.');
            }
            $id = AnalysisPayload::integer($exercise['exercise_id'] ?? null);
            if ($id < 1 || in_array($id, $ids, true)) {
                throw new UnexpectedValueException('Повторное или недопустимое упражнение.');
            }
            $ids[] = $id;
            foreach (['revision', 'successes', 'failures', 'completed_since_replacement', 'completed_since_rejection'] as $key) {
                if (AnalysisPayload::integer($exercise[$key] ?? null) < 0) {
                    throw new UnexpectedValueException('Некорректный счётчик упражнения.');
                }
            }
            $sets = [];
            foreach (AnalysisPayload::list($exercise['sets'] ?? null) as $index => $value) {
                $set = AnalysisPayload::object($value);
                if (count($set) !== 3 || AnalysisPayload::integer($set['position'] ?? null) !== $index + 1
                    || AnalysisPayload::integer($set['repetitions'] ?? null) < 1 || AnalysisPayload::integer($set['working_weight_grams'] ?? null) < 0) {
                    throw new UnexpectedValueException('Некорректный план подходов.');
                }
                $sets[] = ['position' => AnalysisPayload::integer($set['position']), 'repetitions' => AnalysisPayload::integer($set['repetitions']), 'working_weight_grams' => AnalysisPayload::integer($set['working_weight_grams'])];
            }
            $exercises[] = ['exercise_id' => $id, 'sets' => $sets,
                'revision' => AnalysisPayload::integer($exercise['revision']), 'successes' => AnalysisPayload::integer($exercise['successes']),
                'failures' => AnalysisPayload::integer($exercise['failures']), 'completed_since_replacement' => AnalysisPayload::integer($exercise['completed_since_replacement']),
                'completed_since_rejection' => AnalysisPayload::integer($exercise['completed_since_rejection']), 'currently_successful' => $exercise['currently_successful']];
        }
        $catalog = [];
        $ids = [];
        foreach (AnalysisPayload::list($payload['catalog'] ?? null) as $item) {
            $exercise = AnalysisPayload::object($item);
            $id = AnalysisPayload::integer($exercise['id'] ?? null);
            if (count($exercise) !== 2 || $id < 1 || in_array($id, $ids, true) || trim(AnalysisPayload::string($exercise['name'] ?? null)) === '') {
                throw new UnexpectedValueException('Некорректный каталог упражнений.');
            }
            $ids[] = $id;
            $catalog[] = ['id' => $id, 'name' => AnalysisPayload::string($exercise['name'])];
        }

        return ['program_id' => AnalysisPayload::integer($payload['program_id']), 'exercises' => $exercises, 'catalog' => $catalog];
    }
}
