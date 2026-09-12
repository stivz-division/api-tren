<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('planned_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['training_program_id', 'exercise_id']);
            $table->unique(['training_program_id', 'position']);
        });

        Schema::create('planned_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planned_exercise_id')
                ->constrained('planned_exercises')
                ->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedInteger('repetitions');
            $table->unsignedBigInteger('working_weight_grams');

            $table->unique(['planned_exercise_id', 'position']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE planned_exercises
                ADD CONSTRAINT planned_exercises_position_check
                CHECK (position > 0)
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE planned_sets
                ADD CONSTRAINT planned_sets_values_check
                CHECK (position > 0 AND repetitions > 0 AND working_weight_grams >= 0)
                SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planned_sets');
        Schema::dropIfExists('planned_exercises');
    }
};
