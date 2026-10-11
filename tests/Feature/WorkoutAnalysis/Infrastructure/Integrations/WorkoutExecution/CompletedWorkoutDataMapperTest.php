<?php

use App\Models\User;
use App\WorkoutAnalysis\Application\Exceptions\InvalidCompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Application\Factories\CompletedWorkoutSnapshotFactory;
use App\WorkoutAnalysis\Application\Gateways\CompletedWorkoutProvider;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Infrastructure\Integrations\WorkoutExecution\CompletedWorkoutDataMapper;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutExerciseModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutExerciseReplacementModel;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSessionModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('projects the final replacement mode and latest permanent baseline before comparison', function (array $modes) {
    $user = User::factory()->create();
    $session = WorkoutSessionModel::query()->create([
        'user_id' => $user->id, 'training_program_id' => 11, 'training_program_name' => 'План',
        'scheduled_weekday' => 1, 'status' => 'completed',
        'started_at' => '2026-09-10 12:00:00', 'completed_at' => '2026-09-10 13:00:00',
    ]);
    $exercise = $session->workoutExercises()->create([
        'exercise_id' => 31, 'exercise_name' => 'Исходное', 'position' => 1, 'status' => 'completed',
    ]);
    $exercise->plannedSets()->create(['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 50000]);
    $exercise->workoutSets()->create(['position' => 1, 'repetitions' => 8, 'working_weight_grams' => 20000]);
    foreach ($modes as $index => $permanent) {
        $replacement = $exercise->replacements()->create([
            'sequence' => $index + 1, 'request_id' => sprintf('550e8400-e29b-41d4-a716-%012d', $index),
            'exercise_id' => 42 + $index, 'exercise_name' => 'Новое '.$index,
            'replace_in_plan' => $permanent, 'created_at' => '2026-09-10 12:10:00',
            'plan_revision_after' => $permanent ? $index + 1 : null,
        ]);
        $replacement->plannedSets()->create(['position' => 1, 'repetitions' => 8, 'working_weight_grams' => 20000]);
    }
    $ordinary = $session->workoutExercises()->create([
        'exercise_id' => 50, 'exercise_name' => 'Обычное', 'position' => 2, 'status' => 'completed',
    ]);
    $ordinary->plannedSets()->create(['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 30000]);
    $ordinary->workoutSets()->create(['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 30000]);
    $userId = new UserId($user->id);
    $sessionId = new WorkoutSessionId($session->id);

    $data = app(CompletedWorkoutProvider::class)->findForUser($sessionId, $userId)
        ?? throw new LogicException('Нет завершённой тренировки.');
    $snapshot = app(CompletedWorkoutSnapshotFactory::class)->create($data, $userId, $sessionId);
    $result = (new WorkoutDeviationCalculator)->calculate($snapshot);

    $lastPermanent = $modes[array_key_last($modes)];
    if ($lastPermanent) {
        expect($snapshot->exercises->all()[0]->exerciseId->value)->toBe(41 + count($modes));
        expect($snapshot->exercises->all()[0]->name->value)->toBe('Новое '.(count($modes) - 1));
        expect($result->volume->planned)->toBe(460000);
        expect($result->volume->actual)->toBe(460000);
        expect($result->excludedExercisesCount)->toBe(0);
    } else {
        expect($data->exercises)->toHaveCount(1);
        expect($snapshot->exercises->all()[0]->position->value)->toBe(2);
        expect($snapshot->excludedExercises[0]->sourceExerciseId->value)->toBe(31);
        expect($snapshot->excludedExercises[0]->planExerciseId->value)->toBe(count($modes) === 2 ? 42 : 31);
        expect($result->volume->planned)->toBe(300000);
        expect($result->volume->actual)->toBe(300000);
        expect($result->excludedExercisesCount)->toBe(1);
    }
})->with([
    'one time' => [[false]],
    'permanent' => [[true]],
    'permanent then one time' => [[true, false]],
    'one time then permanent' => [[false, true]],
]);

it('requires explicit loading of replacements and their planned sets', function () {
    $session = new WorkoutSessionModel;
    $exercise = new WorkoutExerciseModel;
    $exercise->setRelation('plannedSets', new Collection);
    $exercise->setRelation('workoutSets', new Collection);
    $session->setRelation('workoutExercises', new Collection([$exercise]));

    expect(fn () => app(CompletedWorkoutDataMapper::class)->toData($session))->toThrow(LogicException::class);

    $replacement = new WorkoutExerciseReplacementModel;
    $exercise->setRelation('replacements', new Collection([$replacement]));
    expect(fn () => app(CompletedWorkoutDataMapper::class)->toData($session))->toThrow(LogicException::class);
});

it('rejects a completed source with an unresolved one time replacement', function () {
    $session = new WorkoutSessionModel(['status' => 'completed']);
    $exercise = new WorkoutExerciseModel([
        'exercise_id' => 31, 'position' => 1, 'status' => 'pending',
    ]);
    $exercise->setRelation('plannedSets', new Collection);
    $exercise->setRelation('workoutSets', new Collection);
    $replacement = new WorkoutExerciseReplacementModel([
        'exercise_id' => 42, 'replace_in_plan' => false,
    ]);
    $replacement->setRelation('plannedSets', new Collection);
    $exercise->setRelation('replacements', new Collection([$replacement]));
    $session->setRelation('workoutExercises', new Collection([$exercise]));

    expect(fn () => app(CompletedWorkoutDataMapper::class)->toData($session))
        ->toThrow(InvalidCompletedWorkoutSnapshot::class);
});
