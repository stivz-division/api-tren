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
        Schema::table('workout_exercises', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_plan_revision')->nullable();
        });

        Schema::create('workout_exercise_replacements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workout_exercise_id')->constrained('workout_exercises')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->uuid('request_id');
            $table->unsignedBigInteger('exercise_id');
            $table->text('exercise_name');
            $table->boolean('replace_in_plan');
            $table->timestampTz('created_at', 6);
            $table->unsignedBigInteger('plan_revision_after')->nullable();
            $table->unique(['workout_exercise_id', 'sequence'], 'workout_replacement_sequence_unique');
            $table->unique(['workout_exercise_id', 'request_id'], 'workout_replacement_request_unique');
        });

        Schema::create('workout_replacement_planned_sets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('replacement_id')->constrained('workout_exercise_replacements')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedInteger('repetitions');
            $table->unsignedBigInteger('working_weight_grams');
            $table->unique(['replacement_id', 'position'], 'workout_replacement_set_position_unique');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE workout_exercises ADD CONSTRAINT workout_exercise_source_revision_check
                CHECK (source_plan_revision IS NULL OR source_plan_revision >= 0)
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE workout_exercise_replacements ADD CONSTRAINT workout_replacement_values_check
                CHECK (sequence > 0 AND exercise_id > 0 AND
                    (plan_revision_after IS NULL OR (replace_in_plan AND plan_revision_after >= 0)))
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE workout_replacement_planned_sets ADD CONSTRAINT workout_replacement_set_values_check
                CHECK (position > 0 AND repetitions > 0 AND working_weight_grams >= 0)
                SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workout_replacement_planned_sets');
        Schema::dropIfExists('workout_exercise_replacements');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE workout_exercises DROP CONSTRAINT workout_exercise_source_revision_check');
        }
        Schema::table('workout_exercises', function (Blueprint $table): void {
            $table->dropColumn('source_plan_revision');
        });
    }
};
