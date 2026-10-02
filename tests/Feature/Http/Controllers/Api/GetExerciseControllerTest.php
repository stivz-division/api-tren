<?php

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

it('returns 401 when no token is provided', function (): void {
    $this->getJson('/api/exercises/1')->assertUnauthorized();
});

it('returns the requested exercise with only catalog fields', function (?string $description, ?string $videoUrl): void {
    Exercise::factory()->create(['name' => 'Another exercise']);
    $exercise = Exercise::factory()->create([
        'code' => 'bench-press',
        'name' => 'Bench Press',
        'description' => $description,
        'video_url' => $videoUrl,
    ]);
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/exercises/'.$exercise->id);

    $response
        ->assertOk()
        ->assertExactJson([
            'data' => [
                'id' => $exercise->id,
                'code' => 'bench-press',
                'name' => 'Bench Press',
                'description' => $description,
                'video_url' => $videoUrl,
            ],
        ]);
})->with([
    'with metadata' => [
        'Мышцы: грудные и трицепсы. Техника: опустите гриф к груди и выжмите вверх.',
        'https://www.youtube.com/watch?v=3K259_IsCgg',
    ],
    'without metadata' => [null, null],
]);

it('returns 404 when the exercise ID is missing or invalid', function (string $exerciseId): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/exercises/'.$exerciseId)->assertNotFound();
})->with([
    'missing exercise' => '1',
    'non-numeric ID' => 'unknown',
    'negative ID' => '-1',
]);
