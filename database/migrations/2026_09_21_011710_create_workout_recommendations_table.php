<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workout_recommendations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workout_analysis_id')->constrained('workout_analyses')->cascadeOnDelete();
            $table->unsignedBigInteger('workout_session_id');
            $table->unsignedBigInteger('training_program_id');
            $table->unsignedBigInteger('exercise_id');
            $table->unsignedBigInteger('source_revision')->default(0);
            $table->unsignedBigInteger('replacement_exercise_id')->nullable();
            $table->string('change_type', 30);
            $table->string('status', 20);
            $table->jsonb('original_sets');
            $table->jsonb('proposed_sets');
            $table->text('rationale');
            $table->jsonb('evidence');
            $table->timestampTz('source_completed_at', 6);
            $table->timestampTz('applied_at', 6)->nullable();
            $table->timestampTz('rejected_at', 6)->nullable();
            $table->timestampTz('expired_at', 6)->nullable();
            $table->timestampsTz(6);
            $table->unique(['workout_analysis_id', 'exercise_id']);
            $table->index(['user_id', 'training_program_id', 'status'], 'recommendations_program_status');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE workout_recommendations ADD CONSTRAINT recommendation_decision_state CHECK (
                (status = 'proposed' AND applied_at IS NULL AND rejected_at IS NULL AND expired_at IS NULL)
                OR (status = 'applied' AND applied_at IS NOT NULL AND applied_at >= source_completed_at AND rejected_at IS NULL AND expired_at IS NULL)
                OR (status = 'rejected' AND rejected_at IS NOT NULL AND rejected_at >= source_completed_at AND applied_at IS NULL AND expired_at IS NULL)
                OR (status = 'expired' AND expired_at IS NOT NULL AND expired_at >= source_completed_at AND applied_at IS NULL AND rejected_at IS NULL)
            )");
            DB::statement("ALTER TABLE workout_recommendations ADD CONSTRAINT recommendation_change_type CHECK (
                (change_type IN ('progression','adjustment') AND replacement_exercise_id IS NULL)
                OR (change_type = 'replacement' AND replacement_exercise_id IS NOT NULL AND replacement_exercise_id <> exercise_id)
            )");
        }
        Schema::create('workout_plan_change_barriers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('training_program_id');
            $table->unsignedBigInteger('exercise_id');
            $table->unsignedBigInteger('revision');
            $table->timestampTz('changed_at', 6);
            $table->unique(['user_id', 'training_program_id', 'exercise_id'], 'plan_change_barrier_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workout_plan_change_barriers');
        Schema::dropIfExists('workout_recommendations');
    }
};
