<?php

namespace App\WorkoutAnalysis\Infrastructure\Integrations\OpenAI;

use App\WorkoutAnalysis\Domain\Collections\SetSnapshotCollection;
use App\WorkoutAnalysis\Domain\Enums\ExerciseCompletionStatus;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseDeviation;
use App\WorkoutAnalysis\Domain\ValueObjects\MetricDeviation;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutDeviationResult;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSetSnapshot;
use UnexpectedValueException;

/**
 * @phpstan-type Evidence array{workout_session_id: int, exercise_id: int|null}
 * @phpstan-type Fact array{text: string, evidence: list<Evidence>, required: bool}
 * @phpstan-type Catalog array{current_workout: array<string, Fact>, history: array<string, Fact>}
 */
final class WorkoutAnalysisFacts
{
    /** @return Catalog */
    public function catalog(AnalysisContextSnapshot $context): array
    {
        $current = $context->currentWorkout;
        $currentId = $current->snapshot->workoutSessionId->value;
        $currentFacts = ['current:summary' => $this->fact($this->summary($current), $currentId)];
        $currentExercises = [];
        foreach ($current->exercises as $exercise) {
            $id = $exercise->snapshot->exerciseId->value;
            $currentExercises[$id] = $exercise;
            $currentFacts['current:exercise:'.$id] = $this->fact($this->exercise($exercise, $currentId), $currentId, $id);
        }

        $historyFacts = [];
        foreach (['same_program' => $context->sameProgram, 'other_programs' => $context->otherPrograms] as $scope => $window) {
            if ($window->all() === []) {
                $label = $scope === 'same_program' ? 'той же программы' : 'других программ';
                $historyFacts['history:'.$scope.':empty'] = $this->fact(
                    'В доступном окне истории нет предыдущих тренировок '.$label.'. Это не означает, что таких тренировок не было.', $currentId,
                );
            }
            $first = true;
            foreach (array_reverse($window->all()) as $entry) {
                $previous = $entry->deviations;
                $previousId = $previous->snapshot->workoutSessionId->value;
                $summary = $scope === 'same_program'
                    ? 'Сравнение тренировок №'.$previousId.' → №'.$currentId.' той же программы: подходы '.$this->metric(new MetricDeviation($previous->sets->actual, $current->sets->actual))
                        .'; повторения '.$this->metric(new MetricDeviation($previous->repetitions->actual, $current->repetitions->actual))
                        .'; объём '.$this->metric(new MetricDeviation($previous->volume->actual, $current->volume->actual), 1000).' кг·повторов.'
                    : 'Другая программа. '.$this->summary($previous).' Суммарный объём разных программ напрямую не сопоставляется.';
                $historyFacts['history:'.$previousId.':summary'] = [
                    'text' => $summary,
                    'required' => $first,
                    'evidence' => [
                        ['workout_session_id' => $previousId, 'exercise_id' => null],
                        ['workout_session_id' => $currentId, 'exercise_id' => null],
                    ],
                ];
                $first = false;
                foreach ($previous->exercises as $exercise) {
                    $id = $exercise->snapshot->exerciseId->value;
                    $matching = $currentExercises[$id] ?? null;
                    if ($matching === null) {
                        continue;
                    }
                    $historyFacts['history:'.$previousId.':exercise:'.$id] = [
                        'text' => $this->exercise($exercise, $previousId).' '.$this->exercise($matching, $currentId),
                        'required' => false,
                        'evidence' => [
                            ['workout_session_id' => $previousId, 'exercise_id' => $id],
                            ['workout_session_id' => $currentId, 'exercise_id' => $id],
                        ],
                    ];
                }
            }
        }

        return ['current_workout' => $currentFacts, 'history' => $historyFacts];
    }

    /**
     * @param  Catalog  $catalog
     * @return array{current_workout: string, history: string, evidence: list<Evidence>}
     */
    public function render(array $catalog, mixed $currentSelection, mixed $historySelection): array
    {
        $current = $this->select($catalog['current_workout'], $currentSelection);
        $history = $this->select($catalog['history'], $historySelection);
        $evidence = [];
        foreach ([...$current, ...$history] as $fact) {
            foreach ($fact['evidence'] as $reference) {
                $key = $reference['workout_session_id'].':'.($reference['exercise_id'] ?? 'workout');
                $evidence[$key] = $reference;
            }
        }

        return [
            'current_workout' => implode("\n\n", array_column($current, 'text')),
            'history' => implode("\n\n", array_column($history, 'text')),
            'evidence' => array_values($evidence),
        ];
    }

    /**
     * @param  array<string, Fact>  $facts
     * @return list<Fact>
     */
    private function select(array $facts, mixed $selection): array
    {
        if (! is_array($selection) || ! array_is_list($selection) || $selection === []) {
            throw new UnexpectedValueException('Ожидался список фактов раздела.');
        }
        $selected = [];
        foreach ($selection as $id) {
            if (! is_string($id) || ! isset($facts[$id]) || isset($selected[$id])) {
                throw new UnexpectedValueException('Факт отсутствует в разделе или повторяется.');
            }
            $selected[$id] = $facts[$id];
        }
        foreach ($facts as $id => $fact) {
            if ($fact['required']) {
                $selected[$id] ??= $fact;
            }
        }

        return array_values($selected);
    }

    private function summary(WorkoutDeviationResult $result): string
    {
        return 'Тренировка №'.$result->snapshot->workoutSessionId->value.' «'.$result->snapshot->programName->value.'»: завершено упражнений '.$result->completedExercises
            .', пропущено '.$result->skippedExercises.'. План → факт: подходы '.$this->metric($result->sets)
            .'; повторения '.$this->metric($result->repetitions).'; объём '.$this->metric($result->volume, 1000).' кг·повторов.';
    }

    private function exercise(ExerciseDeviation $exercise, int $sessionId): string
    {
        $snapshot = $exercise->snapshot;
        $status = $snapshot->status === ExerciseCompletionStatus::Skipped
            ? 'Упражнение пропущено.'
            : ($snapshot->plannedSets == $snapshot->actualSets ? 'Без отклонений от плана.'
                : ($exercise->planFulfilled ? 'План выполнен с превышением плановых показателей.' : 'План не выполнен полностью.'));

        return 'Тренировка №'.$sessionId.', «'.$snapshot->name->value.'»: план — '.$this->sets($snapshot->plannedSets)
            .'; факт — '.$this->sets($snapshot->actualSets).'. '.$status;
    }

    private function sets(SetSnapshotCollection $sets): string
    {
        if ($sets->all() === []) {
            return 'нет выполненных подходов';
        }

        return implode('; ', array_map(fn (WorkoutSetSnapshot $set): string => 'подход '.$set->position->value.': '
            .$set->repetitions->value.' × '.$this->number($set->workingWeight->grams / 1000).' кг', $sets->all()));
    }

    private function metric(MetricDeviation $metric, int $divisor = 1): string
    {
        $difference = ($metric->difference > 0 ? '+' : '').$this->number($metric->difference / $divisor);
        $percentage = $metric->percentage === null ? 'процент не определён при нулевой базе'
            : ($metric->percentage > 0 ? '+' : '').$this->number($metric->percentage).'%';

        return $this->number($metric->planned / $divisor).' → '.$this->number($metric->actual / $divisor).' ('.$difference.'; '.$percentage.')';
    }

    private function number(float|int $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', ''), '0'), ',');
    }

    /** @return Fact */
    private function fact(string $text, int $sessionId, ?int $exerciseId = null): array
    {
        return ['text' => $text, 'evidence' => [['workout_session_id' => $sessionId, 'exercise_id' => $exerciseId]], 'required' => true];
    }
}
