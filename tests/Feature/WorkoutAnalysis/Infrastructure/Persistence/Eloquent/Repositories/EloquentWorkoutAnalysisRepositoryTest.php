<?php

use App\Models\User;
use App\WorkoutAnalysis\Domain\Collections\ExercisePerformanceCollection;
use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Enums\ExerciseCompletionStatus;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExcludedExerciseSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\ExercisePosition;
use App\WorkoutAnalysis\Domain\ValueObjects\ProgramName;
use App\WorkoutAnalysis\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\AnalysisPayload;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\CompletedWorkoutSnapshotCodec;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutAnalysisModel;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutDeviationAnalysisModel;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutDeviationAttemptModel;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Repositories\EloquentWorkoutAnalysisRepository;
use App\WorkoutAnalysis\Infrastructure\Queue\CalculateWorkoutDeviationsJob;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture;

uses(LazilyRefreshDatabase::class);

$makeAnalysis = static function (?User $user = null, string $name = 'Историческая программа'): WorkoutAnalysis {
    $user ??= User::factory()->create();
    $snapshot = new CompletedWorkoutSnapshot(
        new WorkoutSessionId(51), new UserId($user->id), new TrainingProgramId(11), new ProgramName($name),
        new DateTimeImmutable('2026-09-17T19:00:00.123456+07:00'),
        new ExercisePerformanceCollection(
            WorkoutAnalysisFixture::exercise(planned: [[10, 50_000], [8, 50_000]], actual: [[9, 45_000]]),
            WorkoutAnalysisFixture::exercise(actual: [], id: 20, position: 2, status: ExerciseCompletionStatus::Skipped),
        ),
    );

    return WorkoutAnalysis::initialize($snapshot, new DateTimeImmutable('2026-09-17T19:00:00.234567+07:00'));
};

it('round trips a pending snapshot with ordered planned and actual sets and UTC microseconds', function () use ($makeAnalysis): void {
    $analysis = $makeAnalysis();
    $repository = app(EloquentWorkoutAnalysisRepository::class);
    $stored = DB::transaction(fn () => $repository->add($analysis));

    $restored = $repository->findForSession(new WorkoutSessionId(51), $analysis->deviations()->snapshot->userId);

    expect($restored)->toEqual($stored);
    expect($restored?->deviations()->snapshot)->toEqual($analysis->deviations()->snapshot);
    expect($restored?->deviations()->currentAttempt()->scheduledAt->format('Y-m-d H:i:s.uP'))
        ->toBe('2026-09-17 12:00:00.234567+00:00');
    expect($restored?->deviations()->snapshot->completedAt->format('Y-m-d H:i:s.uP'))
        ->toBe('2026-09-17 12:00:00.123456+00:00');
    $this->assertDatabaseHas('workout_analyses', ['snapshot_version' => 2, 'workout_session_id' => 51]);
    $this->assertDatabaseHas('workout_deviation_analyses', ['status' => 'pending', 'result_version' => null]);
});

it('round trips completed metrics and retry history without rewriting finalized attempts', function () use ($makeAnalysis): void {
    $repository = app(EloquentWorkoutAnalysisRepository::class);
    $stored = DB::transaction(fn () => $repository->add($makeAnalysis()));
    $start = new DateTimeImmutable('2026-09-17T12:00:01.345678Z');
    $stored->startDeviationAttempt(1, $start, $start->modify('+60 seconds'));
    $stored->failDeviationAttempt(1, AnalysisFailureCode::CalculationFailed, $start->modify('+1 second'), $start->modify('+2 seconds'));
    DB::transaction(fn () => $repository->save($stored));
    $firstAttempt = WorkoutDeviationAttemptModel::query()->where('number', 1)->firstOrFail()->getAttributes();
    $stored->startDeviationAttempt(2, $start->modify('+2 seconds'), $start->modify('+62 seconds'));
    $result = (new WorkoutDeviationCalculator)->calculate($stored->deviations()->snapshot);
    $stored->completeDeviationAttempt(2, $result, $start->modify('+3 seconds'));

    DB::transaction(fn () => $repository->save($stored));
    $restored = $repository->findForUser(($stored->id ?? throw new LogicException('Отсутствует идентификатор сохранённого анализа.')), $stored->deviations()->snapshot->userId);

    expect($restored)->toEqual($stored);
    expect($restored?->deviations()->result)->toEqual($result);
    expect($restored?->deviations()->attempts())->toHaveCount(2);
    expect(WorkoutDeviationAttemptModel::query()->where('number', 1)->firstOrFail()->getAttributes())->toBe($firstAttempt);
    $this->assertDatabaseHas('workout_deviation_analyses', ['status' => 'completed', 'current_attempt_number' => 2, 'result_version' => 2]);
});

it('scopes both lookup paths and saves to the snapshot owner', function () use ($makeAnalysis): void {
    $repository = app(EloquentWorkoutAnalysisRepository::class);
    $stored = DB::transaction(fn () => $repository->add($makeAnalysis()));
    $stranger = User::factory()->create();
    $forged = WorkoutAnalysis::restore(($stored->id ?? throw new LogicException('Отсутствует идентификатор сохранённого анализа.')), $makeAnalysis($stranger)->deviations());

    expect($repository->findForUser(($stored->id ?? throw new LogicException('Отсутствует идентификатор сохранённого анализа.')), new UserId($stranger->id)))->toBeNull();
    expect($repository->findForSession(new WorkoutSessionId(51), new UserId($stranger->id)))->toBeNull();
    expect(fn () => DB::transaction(fn () => $repository->save($forged)))->toThrow(LogicException::class);
    $this->assertDatabaseHas('workout_analyses', ['id' => $stored->id->value, 'user_id' => $stored->deviations()->snapshot->userId->value]);
});

it('prevents a duplicate analysis for the same completed session', function () use ($makeAnalysis): void {
    $repository = app(EloquentWorkoutAnalysisRepository::class);
    $analysis = $makeAnalysis();
    DB::transaction(fn () => $repository->add($analysis));

    expect(fn () => DB::transaction(fn () => $repository->add($analysis)))->toThrow(UniqueConstraintViolationException::class);
    $this->assertDatabaseCount('workout_analyses', 1);
    $this->assertDatabaseCount('workout_deviation_attempts', 1);
});

it('rejects changes to an existing immutable snapshot', function () use ($makeAnalysis): void {
    $repository = app(EloquentWorkoutAnalysisRepository::class);
    $user = User::factory()->create();
    $stored = DB::transaction(fn () => $repository->add($makeAnalysis($user)));
    $changed = WorkoutAnalysis::restore(($stored->id ?? throw new LogicException('Отсутствует идентификатор сохранённого анализа.')), $makeAnalysis($user, 'Подменённая программа')->deviations());

    expect(fn () => DB::transaction(fn () => $repository->save($changed)))->toThrow(LogicException::class);
    expect($repository->findForUser(($stored->id ?? throw new LogicException('Отсутствует идентификатор сохранённого анализа.')), new UserId($user->id))?->deviations()->snapshot->programName->value)
        ->toBe('Историческая программа');
});

it('rejects unsupported snapshot versions and malformed snapshot values', function (string $corruption) use ($makeAnalysis): void {
    $repository = app(EloquentWorkoutAnalysisRepository::class);
    $stored = DB::transaction(fn () => $repository->add($makeAnalysis()));
    $model = WorkoutAnalysisModel::query()->firstOrFail();
    if ($corruption === 'version') {
        $model->update(['snapshot_version' => 999]);
    } else {
        $snapshot = $model->snapshot;
        $snapshot['user_id'] = '1';
        $model->update(['snapshot' => $snapshot]);
    }

    expect(fn () => $repository->findForUser(($stored->id ?? throw new LogicException('Отсутствует идентификатор сохранённого анализа.')), $stored->deviations()->snapshot->userId))
        ->toThrow(UnexpectedValueException::class);
})->with(['version', 'payload']);

it('rejects a stage projection that disagrees with its attempt history', function () use ($makeAnalysis): void {
    $repository = app(EloquentWorkoutAnalysisRepository::class);
    $stored = DB::transaction(fn () => $repository->add($makeAnalysis()));
    WorkoutDeviationAnalysisModel::query()->update(['current_attempt_number' => 9]);

    expect(fn () => $repository->findForUser(($stored->id ?? throw new LogicException('Отсутствует идентификатор сохранённого анализа.')), $stored->deviations()->snapshot->userId))
        ->toThrow(UnexpectedValueException::class);
});

it('rejects a corrupted completed result or unsupported result version', function (string $corruption) use ($makeAnalysis): void {
    $repository = app(EloquentWorkoutAnalysisRepository::class);
    $analysis = $makeAnalysis();
    $start = new DateTimeImmutable('2026-09-17T12:00:01Z');
    $analysis->startDeviationAttempt(1, $start, $start->modify('+60 seconds'));
    $analysis->completeDeviationAttempt(1, (new WorkoutDeviationCalculator)->calculate($analysis->deviations()->snapshot), $start->modify('+1 second'));
    $stored = DB::transaction(fn () => $repository->add($analysis));
    $model = WorkoutDeviationAnalysisModel::query()->firstOrFail();
    if ($corruption === 'version') {
        $model->update(['result_version' => 999]);
    } else {
        $result = $model->result;
        $result['completed_exercises'] = 100;
        $model->update(['result' => $result]);
    }

    expect(fn () => $repository->findForUser(($stored->id ?? throw new LogicException('Отсутствует идентификатор сохранённого анализа.')), $stored->deviations()->snapshot->userId))
        ->toThrow(UnexpectedValueException::class);
})->with(['version', 'payload']);

it('rejects a stale aggregate instead of removing finalized attempt history', function () use ($makeAnalysis): void {
    $repository = app(EloquentWorkoutAnalysisRepository::class);
    $stored = DB::transaction(fn () => $repository->add($makeAnalysis()));
    $stale = clone $stored;
    $start = new DateTimeImmutable('2026-09-17T12:00:01Z');
    $stored->startDeviationAttempt(1, $start, $start->modify('+60 seconds'));
    $stored->failDeviationAttempt(1, AnalysisFailureCode::CalculationFailed, $start->modify('+1 second'), $start->modify('+2 seconds'));
    DB::transaction(fn () => $repository->save($stored));

    expect(fn () => DB::transaction(fn () => $repository->save($stale)))->toThrow(LogicException::class);
    expect($repository->findForUser(($stored->id ?? throw new LogicException('Отсутствует идентификатор сохранённого анализа.')), $stored->deviations()->snapshot->userId)?->deviations()->attempts())->toHaveCount(2);
    $this->assertDatabaseHas('workout_deviation_attempts', ['number' => 1, 'status' => AnalysisStatus::Failed->value]);
});

$legacyFixture = static function (string $name): string {
    $contents = file_get_contents(__DIR__.'/../../../../../../Support/WorkoutAnalysis/Fixtures/'.$name.'-v1.json');

    return $contents === false ? throw new LogicException('Нет v1 fixture.') : $contents;
};

it('recovers a version one in flight analysis and saves a version two result without rewriting its snapshot', function () use ($legacyFixture): void {
    Queue::fake();
    User::factory()->create(['id' => 7]);
    $snapshotBytes = $legacyFixture('snapshot');
    $snapshot = app(CompletedWorkoutSnapshotCodec::class)->decode(
        AnalysisPayload::object(json_decode($snapshotBytes, true, flags: JSON_THROW_ON_ERROR)), 1,
    );
    $now = new DateTimeImmutable('2026-09-17T12:00:00Z');
    $repository = app(EloquentWorkoutAnalysisRepository::class);
    $analysis = DB::transaction(fn () => $repository->add(WorkoutAnalysis::initialize($snapshot, $now)));
    $id = $analysis->id ?? throw new LogicException('Нет ID анализа.');
    DB::table('workout_analyses')->where('id', $id->value)->update(['snapshot' => $snapshotBytes, 'snapshot_version' => 1]);
    $persistedSnapshotBytes = WorkoutAnalysisModel::query()->findOrFail($id->value)->getRawOriginal('snapshot');
    $analysis = $repository->findForUser($id, $snapshot->userId) ?? throw new LogicException('Нет анализа.');
    $analysis->startDeviationAttempt(1, $now, $now->modify('+60 seconds'));
    DB::transaction(fn () => $repository->save($analysis));
    $this->travelTo($now->modify('+60 seconds'));

    expect(Artisan::call('workout-analysis:recover'))->toBe(0);

    Queue::assertPushed(
        CalculateWorkoutDeviationsJob::class,
        fn (CalculateWorkoutDeviationsJob $job): bool => $job->analysisId === $id->value && $job->attemptNumber === 2,
    );
    $recovered = $repository->findForUser($id, $snapshot->userId);
    $retryAt = $recovered->deviations()->currentAttempt()->scheduledAt;
    $recovered->startDeviationAttempt(2, $retryAt, $retryAt->modify('+60 seconds'));
    $recovered->completeDeviationAttempt(2, (new WorkoutDeviationCalculator)->calculate($snapshot), $retryAt->modify('+1 second'));
    DB::transaction(fn () => $repository->save($recovered));
    DB::transaction(fn () => $repository->save($recovered));

    $model = WorkoutAnalysisModel::query()->findOrFail($id->value);
    expect($model->getRawOriginal('snapshot'))->toBe($persistedSnapshotBytes);
    expect($model->snapshot_version)->toBe(1);
    expect($model->deviations?->result_version)->toBe(2);
    expect($repository->findForUser($id, $snapshot->userId)->deviations()->result)->toEqual($recovered->deviations()->result);
});

it('preserves version one snapshot context and completed result bytes through later stage retries', function () use ($legacyFixture): void {
    User::factory()->create(['id' => 7]);
    $snapshotBytes = $legacyFixture('snapshot');
    $resultBytes = $legacyFixture('result');
    $contextBytes = $legacyFixture('context');
    $snapshot = app(CompletedWorkoutSnapshotCodec::class)->decode(
        AnalysisPayload::object(json_decode($snapshotBytes, true, flags: JSON_THROW_ON_ERROR)), 1,
    );
    $now = new DateTimeImmutable('2026-09-17T12:00:00Z');
    $analysis = WorkoutAnalysis::initialize($snapshot, $now);
    $analysis->startDeviationAttempt(1, $now, $now->modify('+60 seconds'));
    $analysis->completeDeviationAttempt(1, (new WorkoutDeviationCalculator)->calculate($snapshot), $now->modify('+1 second'));
    $repository = app(EloquentWorkoutAnalysisRepository::class);
    $analysis = DB::transaction(fn () => $repository->add($analysis));
    $id = $analysis->id ?? throw new LogicException('Нет ID анализа.');
    DB::table('workout_analyses')->where('id', $id->value)->update([
        'snapshot' => $snapshotBytes, 'snapshot_version' => 1,
        'context' => $contextBytes, 'context_version' => 1,
    ]);
    DB::table('workout_deviation_analyses')->where('workout_analysis_id', $id->value)->update([
        'result' => $resultBytes, 'result_version' => 1,
    ]);
    $persistedModel = WorkoutAnalysisModel::query()->findOrFail($id->value);
    $persistedSnapshotBytes = $persistedModel->getRawOriginal('snapshot');
    $persistedContextBytes = $persistedModel->getRawOriginal('context');
    $persistedStage = $persistedModel->deviations ?? throw new LogicException('Нет этапа сравнения.');
    $persistedResultBytes = $persistedStage->getRawOriginal('result');
    $analysis = $repository->findForUser($id, $snapshot->userId) ?? throw new LogicException('Нет анализа.');
    $context = $analysis->context();
    expect($analysis->deviations()->snapshot)->toEqual($snapshot);
    expect($analysis->deviations()->result?->volume->planned)->toBe(500000);
    expect($context?->sameProgram->all()[0]->deviations->snapshot->workoutSessionId->value)->toBe(50);
    $aiAt = new DateTimeImmutable('2026-09-21T12:01:00Z');
    $analysis->scheduleAI($aiAt);
    $analysis->startAIAttempt(1, $aiAt, $aiAt->modify('+60 seconds'));
    $analysis->failAIAttempt(1, AnalysisFailureCode::ProviderUnavailable, $aiAt->modify('+1 second'), $aiAt->modify('+5 seconds'));

    DB::transaction(fn () => $repository->save($analysis));
    DB::transaction(fn () => $repository->save($analysis));

    $model = WorkoutAnalysisModel::query()->findOrFail($id->value);
    expect($model->getRawOriginal('snapshot'))->toBe($persistedSnapshotBytes);
    expect($model->snapshot_version)->toBe(1);
    expect($model->getRawOriginal('context'))->toBe($persistedContextBytes);
    expect($model->context_version)->toBe(1);
    $stage = $model->deviations ?? throw new LogicException('Нет этапа сравнения.');
    expect($stage->getRawOriginal('result'))->toBe($persistedResultBytes);
    expect($stage->result_version)->toBe(1);
    $restored = $repository->findForUser($id, $snapshot->userId);
    expect($restored->context())->toEqual($context);
    expect($restored->ai()?->currentAttempt()->number)->toBe(2);
});

it('persists a version two all excluded comparison and reuses its immutable snapshot and context', function () {
    $user = User::factory()->create();
    $original = WorkoutAnalysisFixture::result(userId: $user->id)->snapshot;
    $snapshot = new CompletedWorkoutSnapshot(
        $original->workoutSessionId, $original->userId, $original->trainingProgramId,
        $original->programName, $original->completedAt, new ExercisePerformanceCollection,
        [new ExcludedExerciseSnapshot(
            new ExerciseId(10),
            new ExerciseId(42),
            new ExercisePosition(1),
        )],
    );
    $repository = app(EloquentWorkoutAnalysisRepository::class);
    $now = new DateTimeImmutable('2026-09-17T12:00:00Z');
    $analysis = DB::transaction(fn () => $repository->add(WorkoutAnalysis::initialize($snapshot, $now)));
    $id = $analysis->id ?? throw new LogicException('Нет ID анализа.');
    $analysis->startDeviationAttempt(1, $now, $now->modify('+60 seconds'));
    $result = (new WorkoutDeviationCalculator)->calculate($snapshot);
    $analysis->completeDeviationAttempt(1, $result, $now->modify('+1 second'));
    $analysis->attachContext(new AnalysisContextSnapshot(
        $result, new WorkoutHistoryWindow,
        new WorkoutHistoryWindow, $now->modify('+2 seconds'),
    ));
    DB::transaction(fn () => $repository->save($analysis));
    $before = WorkoutAnalysisModel::query()->findOrFail($id->value)->getAttributes();

    DB::transaction(fn () => $repository->save($analysis));

    $restored = $repository->findForUser($id, $snapshot->userId) ?? throw new LogicException('Нет анализа.');
    expect($restored)->toEqual($analysis);
    expect($restored->deviations()->result?->hasComparableExercises)->toBeFalse();
    expect($restored->deviations()->result?->excludedExercisesCount)->toBe(1);
    expect(WorkoutAnalysisModel::query()->findOrFail($id->value)->getAttributes())->toBe($before);
    $this->assertDatabaseHas('workout_analyses', ['id' => $id->value, 'snapshot_version' => 2, 'context_version' => 2]);
});
