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

        $validated = Validator::make($catalog, [
            'schema_version' => ['required', 'integer', 'in:1'],
            'discipline' => ['required', 'array:code,name'],
            'discipline.code' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'discipline.name' => ['required', 'string', 'max:255'],
            'exercises' => ['required', 'array', 'min:1'],
            'exercises.*' => ['required', 'array:code,name'],
            'exercises.*.code' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'distinct:strict'],
            'exercises.*.name' => ['required', 'string', 'max:255'],
        ])->validate();

        $schemaVersion = $validated['schema_version'] ?? null;
        $discipline = $validated['discipline'] ?? null;
        $exercises = $validated['exercises'] ?? null;

        if (! is_int($schemaVersion)
            || ! is_array($discipline)
            || ! is_array($exercises)
            || ! array_is_list($exercises)) {
            throw new UnexpectedValueException('The validated exercise catalog contains unexpected types.');
        }

        $disciplineCode = $discipline['code'] ?? null;
        $disciplineName = $discipline['name'] ?? null;

        if (! is_string($disciplineCode) || ! is_string($disciplineName)) {
            throw new UnexpectedValueException('The validated exercise discipline contains unexpected types.');
        }

        $normalizedExercises = [];

        foreach ($exercises as $exercise) {
            if (! is_array($exercise)) {
                throw new UnexpectedValueException('The validated exercise entry contains an unexpected type.');
            }

            $code = $exercise['code'] ?? null;
            $name = $exercise['name'] ?? null;

            if (! is_string($code) || ! is_string($name)) {
                throw new UnexpectedValueException('The validated exercise entry contains unexpected field types.');
            }

            $normalizedExercises[] = [
                'code' => $code,
                'name' => $name,
            ];
        }

        return [
            'schema_version' => $schemaVersion,
            'discipline' => [
                'code' => $disciplineCode,
                'name' => $disciplineName,
            ],
            'exercises' => $normalizedExercises,
        ];
    }
}
