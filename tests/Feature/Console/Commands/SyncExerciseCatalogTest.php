<?php

use App\Models\Discipline;
use App\Models\Exercise;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

uses(LazilyRefreshDatabase::class);

it('imports the expanded gym catalog with technique descriptions and optional videos', function (): void {
    expect(Artisan::call('exercises:sync'))->toBe(0);

    $discipline = Discipline::query()->where('code', 'gym')->sole();
    $catalog = File::json(database_path('data/exercises/gym.json'), JSON_THROW_ON_ERROR);
    $this->assertIsArray($catalog['exercises']);
    expect(count($catalog['exercises']))->toBeGreaterThanOrEqual(60);

    $this->assertDatabaseHas('disciplines', [
        'id' => $discipline->id,
        'name' => 'Тренажёрный зал',
    ]);
    $this->assertDatabaseCount('exercises', count($catalog['exercises']));

    foreach ($catalog['exercises'] as $exercise) {
        $this->assertIsArray($exercise);
        expect($exercise['description'])->toBeString()->toContain('Мышцы:', 'Техника:');

        $this->assertDatabaseHas('exercises', [
            'discipline_id' => $discipline->id,
            'code' => $exercise['code'],
            'name' => $exercise['name'],
            'description' => $exercise['description'],
            'video_url' => $exercise['video_url'] ?? null,
        ]);
    }

    $this->assertDatabaseHas('exercises', [
        'discipline_id' => $discipline->id,
        'code' => 'pull-up',
        'name' => 'Подтягивания',
    ]);
});

it('updates catalog names without changing identifiers or creating duplicates on repeated runs', function (): void {
    $discipline = Discipline::factory()->create([
        'code' => 'gym',
        'name' => 'Old discipline name',
    ]);
    $exercise = Exercise::factory()->for($discipline)->create([
        'code' => 'barbell-bench-press',
        'name' => 'Old exercise name',
    ]);

    expect(Artisan::call('exercises:sync'))->toBe(0);
    $identifiers = Exercise::query()->orderBy('code')->pluck('id', 'code')->all();
    expect(Artisan::call('exercises:sync'))->toBe(0);

    $this->assertDatabaseCount('disciplines', 1);
    $this->assertDatabaseCount('exercises', count($identifiers));
    $this->assertDatabaseHas('disciplines', [
        'id' => $discipline->id,
        'code' => 'gym',
        'name' => 'Тренажёрный зал',
    ]);
    $this->assertDatabaseHas('exercises', [
        'id' => $exercise->id,
        'discipline_id' => $discipline->id,
        'code' => 'barbell-bench-press',
        'name' => 'Жим лежа',
    ]);
    expect(Exercise::query()->orderBy('code')->pluck('id', 'code')->all())->toBe($identifiers);
});

it('preserves exercises absent from the file and exercises in other disciplines', function (): void {
    $gym = Discipline::factory()->create(['code' => 'gym']);
    $customExercise = Exercise::factory()->for($gym)->create(['code' => 'custom-exercise']);
    $otherExercise = Exercise::factory()->create([
        'code' => 'barbell-bench-press',
        'name' => 'Another discipline exercise',
    ]);

    expect(Artisan::call('exercises:sync'))->toBe(0);

    $this->assertModelExists($customExercise);
    $this->assertDatabaseHas('exercises', [
        'id' => $otherExercise->id,
        'discipline_id' => $otherExercise->discipline_id,
        'name' => 'Another discipline exercise',
    ]);
});

it('updates descriptions and video links without replacing existing exercises', function (): void {
    $discipline = Discipline::factory()->create(['code' => 'gym']);
    $exercise = Exercise::factory()->for($discipline)->create([
        'code' => 'barbell-bench-press',
        'description' => 'Old description',
        'video_url' => 'https://www.youtube.com/watch?v=oldVideo123',
    ]);
    File::partialMock()->shouldReceive('json')
        ->once()
        ->with(database_path('data/exercises/gym.json'), JSON_THROW_ON_ERROR)
        ->andReturn([
            'schema_version' => 1,
            'discipline' => ['code' => 'gym', 'name' => 'Тренажёрный зал'],
            'exercises' => [[
                'code' => 'barbell-bench-press',
                'name' => 'Жим лежа',
                'description' => 'Мышцы: грудные. Техника: опустите гриф к груди и выжмите вверх.',
                'video_url' => 'https://www.youtube.com/watch?v=rT7DgCr-3pg',
            ]],
        ]);

    expect(Artisan::call('exercises:sync'))->toBe(0);

    $this->assertDatabaseCount('exercises', 1);
    $this->assertDatabaseHas('exercises', [
        'id' => $exercise->id,
        'description' => 'Мышцы: грудные. Техника: опустите гриф к груди и выжмите вверх.',
        'video_url' => 'https://www.youtube.com/watch?v=rT7DgCr-3pg',
    ]);
});

it('clears metadata when it is omitted or explicitly null in the catalog', function (array $metadata): void {
    $discipline = Discipline::factory()->create(['code' => 'gym']);
    $exercise = Exercise::factory()->for($discipline)->create([
        'code' => 'barbell-bench-press',
        'description' => 'Old description',
        'video_url' => 'https://www.youtube.com/watch?v=oldVideo123',
    ]);
    File::partialMock()->shouldReceive('json')->once()->andReturn([
        'schema_version' => 1,
        'discipline' => ['code' => 'gym', 'name' => 'Тренажёрный зал'],
        'exercises' => [[
            'code' => 'barbell-bench-press',
            'name' => 'Жим лежа',
            ...$metadata,
        ]],
    ]);

    expect(Artisan::call('exercises:sync'))->toBe(0);

    $this->assertDatabaseHas('exercises', [
        'id' => $exercise->id,
        'description' => null,
        'video_url' => null,
    ]);
})->with([
    'omitted' => [[]],
    'null' => [['description' => null, 'video_url' => null]],
]);

it('rejects invalid metadata before writing any catalog records', function (string $field, mixed $value): void {
    File::partialMock()->shouldReceive('json')->once()->andReturn([
        'schema_version' => 1,
        'discipline' => ['code' => 'gym', 'name' => 'Тренажёрный зал'],
        'exercises' => [[
            'code' => 'barbell-bench-press',
            'name' => 'Жим лежа',
            $field => $value,
        ]],
    ]);

    expect(fn (): int => Artisan::call('exercises:sync'))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseCount('disciplines', 0);
    $this->assertDatabaseCount('exercises', 0);
})->with([
    'non-text description' => ['description', ['invalid']],
    'invalid video URL' => ['video_url', 'not-a-url'],
    'unsafe video protocol' => ['video_url', 'javascript:alert(1)'],
    'oversized video URL' => ['video_url', 'https://www.youtube.com/watch?v='.str_repeat('a', 2048)],
]);
