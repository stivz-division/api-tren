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
        Schema::create('workout_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('training_program_id');
            $table->text('training_program_name');
            $table->unsignedTinyInteger('scheduled_weekday');
            $table->enum('status', ['in_progress', 'completed', 'cancelled']);
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('workout_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_session_id')
                ->constrained('workout_sessions')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('exercise_id');
            $table->text('exercise_name');
            $table->unsignedInteger('position');
            $table->unsignedInteger('planned_sets');
            $table->unsignedInteger('planned_repetitions_per_set');
            $table->unsignedBigInteger('planned_working_weight_grams');
            $table->enum('status', ['pending', 'completed', 'skipped']);

            $table->unique(['workout_session_id', 'exercise_id']);
            $table->unique(['workout_session_id', 'position']);
        });

        Schema::create('workout_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workout_exercise_id')
                ->constrained('workout_exercises')
                ->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedInteger('repetitions');
            $table->unsignedBigInteger('working_weight_grams');

            $table->unique(['workout_exercise_id', 'position']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX workout_sessions_user_active_unique
            ON workout_sessions (user_id)
            WHERE status = 'in_progress'
            SQL);

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE workout_sessions
                ADD CONSTRAINT workout_sessions_weekday_check
                CHECK (scheduled_weekday BETWEEN 1 AND 7)
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE workout_sessions
                ADD CONSTRAINT workout_sessions_resolution_check
                CHECK (
                    (status = 'in_progress' AND completed_at IS NULL AND cancelled_at IS NULL)
                    OR
                    (status = 'completed' AND completed_at IS NOT NULL AND cancelled_at IS NULL AND completed_at >= started_at)
                    OR
                    (status = 'cancelled' AND completed_at IS NULL AND cancelled_at IS NOT NULL AND cancelled_at >= started_at)
                )
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE workout_exercises
                ADD CONSTRAINT workout_exercises_values_check
                CHECK (
                    position > 0
                    AND planned_sets > 0
                    AND planned_repetitions_per_set > 0
                    AND planned_working_weight_grams >= 0
                )
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE workout_sets
                ADD CONSTRAINT workout_sets_values_check
                CHECK (position > 0 AND repetitions > 0 AND working_weight_grams >= 0)
                SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workout_sets');
        Schema::dropIfExists('workout_exercises');
        Schema::dropIfExists('workout_sessions');
    }
};
