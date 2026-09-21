<?php

use App\Models\Exercise;
use App\Models\User;
use App\WorkoutAnalysis\Application\Exceptions\RecommendationConflict;
use App\WorkoutAnalysis\Application\Exceptions\RecommendationNotFound;
use App\WorkoutAnalysis\Application\Factories\CompletedWorkoutSnapshotFactory;
use App\WorkoutAnalysis\Application\Gateways\CompletedWorkoutProvider;
use App\WorkoutAnalysis\Application\Gateways\RecommendationPlanGateway;
use App\WorkoutAnalysis\Domain\Entities\WorkoutAnalysis;
use App\WorkoutAnalysis\Domain\Repositories\WorkoutAnalysisRepository;
use App\WorkoutAnalysis\Domain\ValueObjects\AnalysisEvidenceReference;
use App\WorkoutAnalysis\Domain\ValueObjects\RecommendationProposal;
use App\WorkoutAnalysis\Domain\ValueObjects\UserId;
use App\WorkoutAnalysis\Domain\ValueObjects\WorkoutSessionId;
use App\WorkoutAnalysis\Infrastructure\Persistence\Eloquent\Models\WorkoutRecommendationModel;
use App\WorkoutExecution\Application\UseCases\StartWorkoutSession\StartWorkoutSession;
use App\WorkoutExecution\Application\UseCases\StartWorkoutSession\StartWorkoutSessionInput;
use App\WorkoutExecution\Infrastructure\Persistence\Eloquent\Models\WorkoutSessionModel;
use App\WorkoutPlanning\Domain\Collections\PlannedExerciseCollection;
use App\WorkoutPlanning\Domain\Collections\PlannedSetCollection;
use App\WorkoutPlanning\Domain\Entities\PlannedExercise;
use App\WorkoutPlanning\Domain\Repositories\TrainingProgramRepository;
use App\WorkoutPlanning\Domain\ValueObjects\PlannedSet;
use App\WorkoutPlanning\Domain\ValueObjects\Repetitions;
use App\WorkoutPlanning\Domain\ValueObjects\SetPosition;
use App\WorkoutPlanning\Domain\ValueObjects\TrainingProgramId;
use App\WorkoutPlanning\Domain\ValueObjects\UserId as PlanningUserId;
use App\WorkoutPlanning\Domain\ValueObjects\WorkingWeight;
use App\WorkoutPlanning\Infrastructure\Persistence\Eloquent\Models\TrainingProgramModel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config(['workout-planning.mutation_lock.store' => 'array', 'workout-execution.mutation_lock.store' => 'array']);
    $this->travelTo(new DateTimeImmutable('2026-09-21T12:00:00Z'));
});

$fixture = static function (int $completedCount = 3): array {
    $user = User::factory()->create();
    $exercises = [Exercise::factory()->create(), Exercise::factory()->create()];
    $program = TrainingProgramModel::query()->create(['user_id' => $user->id, 'weekday' => 1, 'name' => 'Программа']);
    $sets = [['position' => 1, 'repetitions' => 10, 'working_weight_grams' => 50000]];
    foreach ($exercises as $index => $exercise) {
        $program->plannedExercises()->create(['exercise_id' => $exercise->id, 'position' => $index + 1])->plannedSets()->createMany($sets);
    }
    $session = null;
    for ($i = 0; $i < $completedCount; $i++) {
        $session = WorkoutSessionModel::query()->create([
            'user_id' => $user->id, 'training_program_id' => $program->id, 'training_program_name' => 'Программа', 'scheduled_weekday' => 1,
            'status' => 'completed', 'started_at' => now()->subDays($completedCount - $i)->subHour(), 'completed_at' => now()->subDays($completedCount - $i),
        ]);
        foreach ($exercises as $index => $exercise) {
            $item = $session->workoutExercises()->create(['exercise_id' => $exercise->id, 'exercise_name' => $exercise->name, 'position' => $index + 1, 'status' => 'completed']);
            $item->plannedSets()->createMany($sets);
            $item->workoutSets()->createMany($sets);
        }
    }
    $session ??= throw new LogicException('Missing fixture session.');
    $userId = new UserId($user->id);
    $sessionId = new WorkoutSessionId($session->id);
    $data = app(CompletedWorkoutProvider::class)->findForUser($sessionId, $userId) ?? throw new LogicException('Missing fixture snapshot.');
    $snapshot = app(CompletedWorkoutSnapshotFactory::class)->create($data, $userId, $sessionId);
    $analysis = DB::transaction(fn () => app(WorkoutAnalysisRepository::class)->add(WorkoutAnalysis::initialize($snapshot, now()->toDateTimeImmutable())));
    $gateway = app(RecommendationPlanGateway::class);
    $context = DB::transaction(fn () => $gateway->capture($analysis));
    $proposals = [];
    foreach ($exercises as $exercise) {
        $proposals[] = new RecommendationProposal($exercise->id, 'progression', null, WorkoutAnalysisFixture::sets([[10, 52500]]), 'Три успешных выполнения.', new AnalysisEvidenceReference($analysis->id ?? throw new LogicException('Missing analysis ID.'), $sessionId));
    }
    DB::transaction(fn () => $gateway->store($analysis, $context, $proposals, now()->toDateTimeImmutable()));
    $allItems = WorkoutRecommendationModel::query()->orderBy('id')->get();
    $items = [$allItems->get(0) ?? throw new LogicException('Missing item.'), $allItems->get(1) ?? throw new LogicException('Missing item.')];

    return compact('user', 'exercises', 'program', 'analysis', 'gateway', 'context', 'items', 'session', 'proposals');
};

it('applies only one target atomically and keeps decisions idempotent', function () use ($fixture): void {
    $f = $fixture();
    $item = $f['items'][0];
    $result = $f['gateway']->act($f['user']->id, $item->id, 'apply');
    expect($result->status)->toBe('applied');
    expect($f['program']->plannedExercises()->with('plannedSets')->firstOrFail()->plannedSets->firstOrFail()->working_weight_grams)->toBe(52500);
    expect($f['items'][1]->refresh()->status)->toBe('proposed');
    expect($item->refresh()->expired_at)->toBeNull();
    $this->travel(1)->hour();
    expect($f['gateway']->act($f['user']->id, $item->id, 'apply')->appliedAt)->toEqual($result->appliedAt);
    expect(fn () => $f['gateway']->act($f['user']->id, $item->id, 'reject'))->toThrow(RecommendationConflict::class);
    expect(fn () => $f['gateway']->act(User::factory()->create()->id, $item->id, 'apply'))->toThrow(RecommendationNotFound::class);
});

it('rejects independently and counts all completed program history beyond context windows', function () use ($fixture): void {
    $f = $fixture(30);
    expect($f['context']['exercises'][0]['completed_since_replacement'])->toBe(30);
    expect($f['context']['exercises'][0]['successes'])->toBe(30);
    $first = $f['gateway']->act($f['user']->id, $f['items'][0]->id, 'reject');
    $this->travel(1)->hour();
    expect($f['gateway']->act($f['user']->id, $first->id, 'reject')->rejectedAt)->toEqual($first->rejectedAt);
    expect($f['gateway']->act($f['user']->id, $f['items'][1]->id, 'apply')->status)->toBe('applied');
});

it('expires recommendations on a new start while snapshotting the applied plan', function () use ($fixture): void {
    $f = $fixture();
    $f['gateway']->act($f['user']->id, $f['items'][0]->id, 'apply');
    $started = app(StartWorkoutSession::class)->handle(new StartWorkoutSessionInput($f['user']->id, $f['program']->id));
    expect($f['items'][1]->refresh()->status)->toBe('expired');
    expect($f['gateway']->act($f['user']->id, $f['items'][1]->id, 'apply')->status)->toBe('expired');
    $session = WorkoutSessionModel::query()->findOrFail($started->id);
    expect($session->workoutExercises()->firstOrFail()->plannedSets()->firstOrFail()->working_weight_grams)->toBe(52500);
});

it('expires only manually changed targets and retains the barrier after restoring their old sets', function () use ($fixture): void {
    $f = $fixture();
    $repo = app(TrainingProgramRepository::class);
    $program = $repo->findForUser(new TrainingProgramId($f['program']->id), new PlanningUserId($f['user']->id)) ?? throw new LogicException('Missing program.');
    $original = $program->plannedExercises()[0];
    $changed = new PlannedExercise($original->exerciseId,
        new PlannedSetCollection(new PlannedSet(
            new SetPosition(1), new Repetitions(8), new WorkingWeight(50000),
        )), $original->position);
    $program->replaceExercises(new PlannedExerciseCollection($changed, $program->plannedExercises()[1]));
    $repo->save($program);
    expect($f['items'][0]->refresh()->status)->toBe('expired');
    expect($f['items'][1]->refresh()->status)->toBe('proposed');
    $program->replaceExercises(new PlannedExerciseCollection($original, $program->plannedExercises()[1]));
    $repo->save($program);
    $context = DB::transaction(fn () => $f['gateway']->capture($f['analysis']));
    expect($context['exercises'][0]['revision'])->toBe(2);
    expect($context['exercises'][0]['successes'])->toBe(0);
    expect($context['exercises'][1]['successes'])->toBe(3);
});

it('retains history when a program is deleted and expires pending decisions', function () use ($fixture): void {
    $f = $fixture();
    $f['gateway']->act($f['user']->id, $f['items'][0]->id, 'reject');
    $repo = app(TrainingProgramRepository::class);
    $repo->delete($repo->findForUser(new TrainingProgramId($f['program']->id), new PlanningUserId($f['user']->id)) ?? throw new LogicException('Missing program.'));
    expect($f['items'][0]->refresh()->status)->toBe('rejected');
    expect($f['items'][1]->refresh()->status)->toBe('expired');
    expect($f['gateway']->recommendations($f['user']->id, ($f['analysis']->id ?? throw new LogicException('Missing analysis ID.'))->value))->toHaveCount(2);
});

it('expires late generated suggestions after a newer session started', function () use ($fixture): void {
    $f = $fixture();
    WorkoutRecommendationModel::query()->delete();
    app(StartWorkoutSession::class)->handle(new StartWorkoutSessionInput($f['user']->id, $f['program']->id));
    DB::transaction(fn () => $f['gateway']->store($f['analysis'], $f['context'], $f['proposals'], now()->toDateTimeImmutable()));
    expect(WorkoutRecommendationModel::query()->where('status', 'expired')->count())->toBe(2);
});

it('applies sequential replacements from one batch without rerunning the program cooldown', function () use ($fixture): void {
    $f = $fixture();
    $replacementExercises = [Exercise::factory()->create(), Exercise::factory()->create()];
    foreach ($f['items'] as $index => $item) {
        $item->update(['change_type' => 'replacement', 'replacement_exercise_id' => $replacementExercises[$index]->id]);
    }
    foreach ($f['items'] as $item) {
        expect($f['gateway']->act($f['user']->id, $item->id, 'apply')->status)->toBe('applied');
    }
    expect($f['program']->plannedExercises()->pluck('exercise_id')->all())->toBe(array_map(fn (Exercise $exercise): int => $exercise->id, $replacementExercises));
    $context = DB::transaction(fn () => $f['gateway']->capture($f['analysis']));
    expect($context['exercises'][0]['completed_since_replacement'])->toBe(0);
});

it('expires a conflicting replacement without preventing another independent suggestion', function () use ($fixture): void {
    $f = $fixture();
    $f['items'][0]->update(['change_type' => 'replacement', 'replacement_exercise_id' => $f['exercises'][1]->id]);
    expect($f['gateway']->act($f['user']->id, $f['items'][0]->id, 'apply')->status)->toBe('expired');
    expect($f['items'][0]->refresh()->expired_at)->not->toBeNull();
    expect($f['gateway']->act($f['user']->id, $f['items'][1]->id, 'apply')->status)->toBe('applied');
});

it('starts the replacement rejection cooldown at the first rejection in each batch', function () use ($fixture): void {
    $f = $fixture();
    $replacementExercises = [Exercise::factory()->create(), Exercise::factory()->create()];
    foreach ($f['items'] as $index => $item) {
        $item->update(['change_type' => 'replacement', 'replacement_exercise_id' => $replacementExercises[$index]->id]);
    }
    $f['gateway']->act($f['user']->id, $f['items'][0]->id, 'reject');
    $firstRejectedAt = $f['items'][0]->refresh()->rejected_at ?? throw new LogicException('Missing rejected timestamp.');
    $this->travel(1)->hour();
    $f['gateway']->act($f['user']->id, $f['items'][1]->id, 'reject');
    expect($f['items'][0]->refresh()->rejected_at)->toEqual($firstRejectedAt);
    expect($f['items'][1]->refresh()->rejected_at)->toBeGreaterThan($firstRejectedAt);
    $context = DB::transaction(fn () => $f['gateway']->capture($f['analysis']));
    expect($context['exercises'][0]['completed_since_rejection'])->toBe(0);
});

it('rolls back both the target update and decision when persisting a changed plan fails', function () use ($fixture): void {
    $f = $fixture();
    $item = $f['items'][0];
    DB::statement("CREATE TRIGGER reject_plan_changes BEFORE INSERT ON planned_sets BEGIN SELECT RAISE(ABORT, 'plan unavailable'); END");
    try {
        expect(fn () => $f['gateway']->act($f['user']->id, $item->id, 'apply'))->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER reject_plan_changes');
    }
    expect($item->refresh()->status)->toBe('proposed');
    expect($f['program']->plannedExercises()->firstOrFail()->plannedSets()->firstOrFail()->working_weight_grams)->toBe(50000);
})->skip(fn () => DB::getDriverName() !== 'sqlite', 'SQLite trigger for the injected persistence failure.');
