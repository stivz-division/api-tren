<?php

use App\Models\User;
use App\WorkoutAnalysis\Application\Policies\AnalysisHistoryPolicy;
use App\WorkoutAnalysis\Application\UseCases\PrepareWorkoutAnalysisContext\PrepareWorkoutAnalysisContext;
use App\WorkoutAnalysis\Application\UseCases\PrepareWorkoutAnalysisContext\PrepareWorkoutAnalysisContextInput;
use App\WorkoutAnalysis\Domain\Collections\WorkoutHistoryWindow;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisContextSnapshot;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Mappers\WorkoutAnalysisMapper;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutAnalysisModel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture;

uses(LazilyRefreshDatabase::class);

$completedAnalysis = static function (): WorkoutAnalysis {
    $user = User::factory()->create();
    $result = WorkoutAnalysisFixture::result(userId: $user->id);
    $now = new DateTimeImmutable('2026-09-17T12:00:00Z');
    $analysis = WorkoutAnalysis::initialize($result->snapshot, $now);
    $analysis->startDeviationAttempt(1, $now, $now->modify('+1 minute'));
    $analysis->completeDeviationAttempt(1, $result, $now->modify('+1 second'));

    return DB::transaction(fn () => app(WorkoutAnalysisRepository::class)->add($analysis));
};

it('prepares a persisted context through DI and reuses it after config and time change', function () use ($completedAnalysis): void {
    Queue::fake();
    $this->travelTo(new DateTimeImmutable('2026-09-21T12:00:00.123456Z'));
    $analysis = $completedAnalysis();
    $analysisId = $analysis->id ?? throw new LogicException('Отсутствует ID анализа.');
    config()->set('workout-analysis.history.same_program_limit', 3);
    config()->set('workout-analysis.history.other_programs_limit', 7);
    $input = new PrepareWorkoutAnalysisContextInput($analysis->deviations()->snapshot->userId->value, $analysisId->value);

    $context = app(PrepareWorkoutAnalysisContext::class)->handle($input);
    $this->assertDatabaseHas('workout_analyses', ['id' => $analysisId->value, 'context_version' => 1]);
    $restored = app(WorkoutAnalysisRepository::class)->findForUser($analysisId, $analysis->deviations()->snapshot->userId);
    expect($restored?->context())->toEqual($context);
    expect($context->sameProgram->limit)->toBe(3);
    expect($context->otherPrograms->limit)->toBe(7);
    config()->set('workout-analysis.history.same_program_limit', 1);
    $this->app->forgetInstance(AnalysisHistoryPolicy::class);
    $this->travel(1)->days();

    expect(app(PrepareWorkoutAnalysisContext::class)->handle($input))->toEqual($context);
    $this->assertDatabaseCount('workout_analyses', 1);
    Queue::assertNothingPushed();
});

it('rejects replacement or removal of a saved context by a stale aggregate', function (bool $replace) use ($completedAnalysis): void {
    $analysis = $completedAnalysis();
    $analysisId = $analysis->id ?? throw new LogicException('Отсутствует ID анализа.');
    $stale = clone $analysis;
    $result = $analysis->deviations()->result ?? throw new LogicException('Отсутствует результат.');
    $context = new AnalysisContextSnapshot($result, new WorkoutHistoryWindow, new WorkoutHistoryWindow, new DateTimeImmutable('2026-09-21T12:00:00Z'));
    $analysis->attachContext($context);
    $repository = app(WorkoutAnalysisRepository::class);
    DB::transaction(fn () => $repository->save($analysis));
    DB::transaction(fn () => $repository->save($analysis));
    if ($replace) {
        $stale->attachContext(new AnalysisContextSnapshot($result, new WorkoutHistoryWindow(2), new WorkoutHistoryWindow, $context->capturedAt));
    }

    expect(fn () => DB::transaction(fn () => $repository->save($stale)))->toThrow(LogicException::class);
    expect($repository->findForUser($analysisId, $result->snapshot->userId)?->context())->toEqual($context);
})->with([false, true]);

it('rolls context persistence back with the outer transaction', function () use ($completedAnalysis): void {
    $this->travelTo(new DateTimeImmutable('2026-09-21T12:00:00Z'));
    $analysis = $completedAnalysis();
    $analysisId = $analysis->id ?? throw new LogicException('Отсутствует ID анализа.');
    DB::beginTransaction();
    try {
        app(PrepareWorkoutAnalysisContext::class)->handle(new PrepareWorkoutAnalysisContextInput($analysis->deviations()->snapshot->userId->value, $analysisId->value));
    } finally {
        DB::rollBack();
    }

    expect(WorkoutAnalysisModel::query()->findOrFail($analysisId->value)->context)->toBeNull();
    expect(WorkoutAnalysisModel::query()->findOrFail($analysisId->value)->context_version)->toBeNull();
});

it('stores a context already attached when the aggregate is first added', function () use ($completedAnalysis): void {
    $analysis = $completedAnalysis();
    $result = $analysis->deviations()->result ?? throw new LogicException('Отсутствует результат.');
    $new = WorkoutAnalysis::initialize($result->snapshot, new DateTimeImmutable('2026-09-20T12:00:00Z'));
    $now = new DateTimeImmutable('2026-09-20T12:00:00Z');
    $new->startDeviationAttempt(1, $now, $now->modify('+1 minute'));
    $new->completeDeviationAttempt(1, $result, $now->modify('+1 second'));
    $context = new AnalysisContextSnapshot($result, new WorkoutHistoryWindow, new WorkoutHistoryWindow, $now->modify('+2 seconds'));
    $new->attachContext($context);
    WorkoutAnalysisModel::query()->delete();

    $stored = DB::transaction(fn () => app(WorkoutAnalysisRepository::class)->add($new));

    expect($stored->context())->toEqual($context);
});

it('rejects unknown context versions when restoring from storage', function () use ($completedAnalysis): void {
    $this->travelTo(new DateTimeImmutable('2026-09-21T12:00:00Z'));
    $analysis = $completedAnalysis();
    $id = $analysis->id ?? throw new LogicException('Отсутствует анализ.');
    app(PrepareWorkoutAnalysisContext::class)->handle(new PrepareWorkoutAnalysisContextInput($analysis->deviations()->snapshot->userId->value, $id->value));
    WorkoutAnalysisModel::query()->whereKey($id->value)->update(['context_version' => 999]);

    expect(fn () => app(WorkoutAnalysisRepository::class)->findForUser($id, $analysis->deviations()->snapshot->userId))
        ->toThrow(UnexpectedValueException::class);
});

it('rejects nonpositive configured history limits', function (): void {
    config()->set('workout-analysis.history.other_programs_limit', 0);

    expect(fn () => app(AnalysisHistoryPolicy::class))->toThrow(InvalidArgumentException::class);
});

it('rejects a context payload without its version or a version without its payload', function (bool $missingVersion) use ($completedAnalysis): void {
    $this->travelTo(new DateTimeImmutable('2026-09-21T12:00:00Z'));
    $analysis = $completedAnalysis();
    $id = $analysis->id ?? throw new LogicException('Отсутствует анализ.');
    app(PrepareWorkoutAnalysisContext::class)->handle(new PrepareWorkoutAnalysisContextInput($analysis->deviations()->snapshot->userId->value, $id->value));
    $model = WorkoutAnalysisModel::query()->with('deviations.attempts', 'ai.attempts')->findOrFail($id->value);
    $model->fill($missingVersion ? ['context_version' => null] : ['context' => null]);

    expect(fn () => app(WorkoutAnalysisMapper::class)->toDomain($model))
        ->toThrow(UnexpectedValueException::class);
})->with([true, false]);
