<?php

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(LazilyRefreshDatabase::class);

it('returns 401 when no token is provided', function (): void {
    $this->getJson('/api/exercises')->assertUnauthorized();
});

it('returns exercises ordered by name with only catalog fields', function (): void {
    $squat = Exercise::factory()->create([
        'code' => 'squat',
        'name' => 'Squat',
    ]);
    $benchPress = Exercise::factory()->create([
        'code' => 'bench-press',
        'name' => 'Bench Press',
        'description' => 'Мышцы: грудные и трицепсы. Техника: опустите гриф к груди и выжмите вверх.',
        'video_url' => 'https://www.youtube.com/watch?v=rT7DgCr-3pg',
    ]);
    $deadlift = Exercise::factory()->create([
        'code' => 'deadlift',
        'name' => 'Deadlift',
    ]);
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/exercises');

    $response
        ->assertOk()
        ->assertExactJson([
            'data' => [
                [
                    'id' => $benchPress->id,
                    'code' => 'bench-press',
                    'name' => 'Bench Press',
                    'description' => 'Мышцы: грудные и трицепсы. Техника: опустите гриф к груди и выжмите вверх.',
                    'video_url' => 'https://www.youtube.com/watch?v=rT7DgCr-3pg',
                ],
                [
                    'id' => $deadlift->id,
                    'code' => 'deadlift',
                    'name' => 'Deadlift',
                    'description' => null,
                    'video_url' => null,
                ],
                [
                    'id' => $squat->id,
                    'code' => 'squat',
                    'name' => 'Squat',
                    'description' => null,
                    'video_url' => null,
                ],
            ],
        ]);
});

it('returns an empty data list when the exercise catalog is empty', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/exercises');

    $response
        ->assertOk()
        ->assertExactJson(['data' => []]);
});
