<?php

use App\Models\Discipline;
use App\Models\Exercise;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(LazilyRefreshDatabase::class);

it('imports the gym catalog including pull-ups', function (): void {
    expect(Artisan::call('exercises:sync'))->toBe(0);

    $discipline = Discipline::query()->where('code', 'gym')->sole();
    $catalog = File::json(database_path('data/exercises/gym.json'), JSON_THROW_ON_ERROR);
    $this->assertIsArray($catalog['exercises']);

    $this->assertDatabaseHas('disciplines', [
        'id' => $discipline->id,
        'name' => 'Тренажёрный зал',
    ]);
    $this->assertDatabaseCount('exercises', count($catalog['exercises']));

    foreach ($catalog['exercises'] as $exercise) {
        $this->assertIsArray($exercise);

        $this->assertDatabaseHas('exercises', [
            'discipline_id' => $discipline->id,
            'code' => $exercise['code'],
            'name' => $exercise['name'],
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
