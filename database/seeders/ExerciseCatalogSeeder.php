<?php

namespace Database\Seeders;

use App\Models\Discipline;
use App\Models\Exercise;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use UnexpectedValueException;

class ExerciseCatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $catalog = $this->catalog();

        DB::transaction(function () use ($catalog): void {
            $disciplineData = $catalog['discipline'];
            $discipline = Discipline::query()->updateOrCreate(
                ['code' => $disciplineData['code']],
                [
                    'name' => $disciplineData['name'],
                ],
            );

            $exercises = array_map(
                fn (array $exercise): array => [
                    'discipline_id' => $discipline->getKey(),
                    'code' => $exercise['code'],
                    'name' => $exercise['name'],
                ],
                $catalog['exercises'],
            );

            Exercise::query()->upsert(
                $exercises,
                ['discipline_id', 'code'],
                ['name'],
            );
        });
    }

    /**
     * Read and validate the exercise catalog.
     *
     * @return array{
     *     schema_version: int,
     *     discipline: array{code: string, name: string},
     *     exercises: list<array{code: string, name: string}>
     * }
     */
    private function catalog(): array
    {
        $catalog = File::json(
            database_path('data/exercises/gym.json'),
            JSON_THROW_ON_ERROR,
        );

        if (! is_array($catalog)) {
            throw new UnexpectedValueException('The exercise catalog must contain a JSON object.');
        }

        return Validator::make($catalog, [
            'schema_version' => ['required', 'integer', 'in:1'],
            'discipline' => ['required', 'array:code,name'],
            'discipline.code' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'discipline.name' => ['required', 'string', 'max:255'],
            'exercises' => ['required', 'array', 'min:1'],
            'exercises.*' => ['required', 'array:code,name'],
            'exercises.*.code' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'distinct:strict'],
            'exercises.*.name' => ['required', 'string', 'max:255'],
        ])->validate();
    }
}
