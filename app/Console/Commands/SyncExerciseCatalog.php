<?php

namespace App\Console\Commands;

use Database\Seeders\ExerciseCatalogSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('exercises:sync')]
#[Description('Synchronize the exercise catalog from database/data/exercises/gym.json')]
class SyncExerciseCatalog extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ExerciseCatalogSeeder $seeder): int
    {
        $seeder->run();

        return self::SUCCESS;
    }
}
