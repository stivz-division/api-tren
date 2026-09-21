<?php

use App\Models\User;
use App\WorkoutAnalysis\Application\Exceptions\RecommendationNotFound;
use App\WorkoutAnalysis\Application\Gateways\RecommendationPlanGateway;
use App\WorkoutAnalysis\Domain\ValueObjects\ExerciseId;
use App\WorkoutAnalysis\Domain\ValueObjects\HistoricalRecommendation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\WorkoutAnalysis\WorkoutAnalysisFixture;

uses(LazilyRefreshDatabase::class);

it('requires authentication for recommendation actions', function (string $action): void {
    $this->postJson('/api/workout-recommendations/7/'.$action)->assertUnauthorized();
})->with(['apply', 'reject']);

it('returns the individual decision in kilograms using authenticated and route identities', function (string $action, string $status): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $date = new DateTimeImmutable('2026-09-21T10:00:00+00:00');
    $recommendation = new HistoricalRecommendation(
        7, new ExerciseId(10), WorkoutAnalysisFixture::sets([[10, 50_000]]),
        'progression', null, WorkoutAnalysisFixture::sets([[10, 52_500]]),
        'Три успешных выполнения.', $status,
        appliedAt: $status === 'applied' ? $date : null,
        rejectedAt: $status === 'rejected' ? $date : null,
    );
    $gateway = Mockery::mock(RecommendationPlanGateway::class);
    $gateway->shouldReceive('act')->once()->with($user->id, 7, $action)->andReturn($recommendation);
    $this->app->instance(RecommendationPlanGateway::class, $gateway);

    $this->postJson('/api/workout-recommendations/7/'.$action, ['user_id' => 999, 'recommendation_id' => 999])
        ->assertOk()->assertJsonPath('data.id', 7)
        ->assertJsonPath('data.status', $status)
        ->assertJsonPath('data.original_sets.0.working_weight_kg', 50)
        ->assertJsonPath('data.proposed_sets.0.working_weight_kg', 52.5)
        ->assertJsonMissingPath('data.user_id');
})->with(['apply' => ['apply', 'applied'], 'reject' => ['reject', 'rejected']]);

it('maps missing and foreign recommendations to the same safe response', function (string $action): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $gateway = Mockery::mock(RecommendationPlanGateway::class);
    $gateway->shouldReceive('act')->once()->with($user->id, 7, $action)->andThrow(new RecommendationNotFound);
    $this->app->instance(RecommendationPlanGateway::class, $gateway);

    $this->postJson('/api/workout-recommendations/7/'.$action)->assertNotFound()
        ->assertExactJson(['code' => 'workout_recommendation_not_found', 'message' => 'Рекомендация не найдена.']);
})->with(['apply', 'reject']);

it('rejects invalid recommendation route identifiers', function (string $id): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/workout-recommendations/'.$id.'/apply')
        ->assertUnprocessable()->assertJsonValidationErrors('recommendation_id');
})->with(['0', '99999999999999999999999']);

it('returns a conflict for an expired recommendation', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $recommendation = new HistoricalRecommendation(
        7, new ExerciseId(10), WorkoutAnalysisFixture::sets([[10, 50_000]]),
        'progression', null, WorkoutAnalysisFixture::sets([[10, 52_500]]),
        'Три успешных выполнения.', 'expired',
        expiredAt: new DateTimeImmutable('2026-09-21T10:00:00Z'),
    );
    $gateway = Mockery::mock(RecommendationPlanGateway::class);
    $gateway->shouldReceive('act')->once()->with($user->id, 7, 'apply')->andReturn($recommendation);
    $this->app->instance(RecommendationPlanGateway::class, $gateway);

    $this->postJson('/api/workout-recommendations/7/apply')->assertConflict()
        ->assertJsonPath('code', 'workout_recommendation_conflict');
});
