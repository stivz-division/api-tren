<?php

use App\Models\User;
use App\WorkoutAnalysis\Domain\Collections\ExercisePerformanceCollection;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\Enums\AnalysisFailureCode;
use App\WorkoutAnalysis\Domain\Enums\AnalysisStatus;
use App\WorkoutAnalysis\Domain\Enums\ExerciseCompletionStatus;
use App\WorkoutAnalysis\Domain\Services\WorkoutDeviationCalculator;
use App\WorkoutAnalysis\Domain\ValueObjects\CompletedWorkoutSnapshot;
use App\WorkoutAnalysis\Domain\ValueObjects\ProgramName;
use App\WorkoutAnalysis\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutAnalysisModel;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutDeviationAnalysisModel;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutDeviationAttemptModel;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Repositories\EloquentWorkoutAnalysisRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    $this->assertDatabaseHas('workout_analyses', ['snapshot_version' => 1, 'workout_session_id' => 51]);
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
    $this->assertDatabaseHas('workout_deviation_analyses', ['status' => 'completed', 'current_attempt_number' => 2, 'result_version' => 1]);
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
        $model->update(['snapshot_version' => 2]);
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
        $model->update(['result_version' => 2]);
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
