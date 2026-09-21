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
        Schema::create('workout_ai_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workout_analysis_id')->unique()->constrained('workout_analyses')->cascadeOnDelete();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed']);
            $table->unsignedInteger('current_attempt_number');
            $table->timestampTz('scheduled_at', 6);
            $table->timestampTz('expires_at', 6)->nullable();
            $table->jsonb('result')->nullable();
            $table->unsignedSmallInteger('result_version')->nullable();
            $table->timestampsTz(6);
        });

        Schema::create('workout_ai_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workout_ai_analysis_id')->constrained('workout_ai_analyses')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedInteger('cycle_attempt');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed']);
            $table->timestampTz('scheduled_at', 6);
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('expires_at', 6)->nullable();
            $table->timestampTz('finished_at', 6)->nullable();
            $table->enum('failure_code', ['provider_unavailable', 'provider_rejected', 'ai_refused', 'invalid_ai_response', 'incomplete_ai_response', 'context_preparation_failed', 'worker_failed', 'attempt_timed_out'])->nullable();
            $table->unique(['workout_ai_analysis_id', 'number'], 'workout_ai_attempt_number_unique');
        });

        DB::statement("CREATE INDEX workout_ai_pending_due ON workout_ai_analyses (scheduled_at, id) WHERE status = 'pending'");
        DB::statement("CREATE INDEX workout_ai_processing_due ON workout_ai_analyses (expires_at, id) WHERE status = 'processing'");

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE workout_ai_analyses ADD CONSTRAINT workout_ai_result_check
                CHECK (
                    current_attempt_number > 0 AND
                    ((status = 'completed' AND result IS NOT NULL AND result_version IS NOT NULL AND result_version > 0 AND jsonb_typeof(result) = 'object')
                    OR (status <> 'completed' AND result IS NULL AND result_version IS NULL)) AND
                    ((status = 'pending' AND expires_at IS NULL) OR (status <> 'pending' AND expires_at IS NOT NULL))
                )
                SQL);
            DB::statement(<<<'SQL'
                ALTER TABLE workout_ai_attempts ADD CONSTRAINT workout_ai_attempt_state_check
                CHECK (
                    number > 0 AND cycle_attempt BETWEEN 1 AND number AND (
                        (status = 'pending' AND started_at IS NULL AND expires_at IS NULL AND finished_at IS NULL AND failure_code IS NULL)
                        OR (
                            started_at IS NOT NULL AND expires_at IS NOT NULL AND started_at >= scheduled_at AND expires_at > started_at AND (
                                (status = 'processing' AND finished_at IS NULL AND failure_code IS NULL)
                                OR (status = 'completed' AND finished_at IS NOT NULL AND finished_at >= started_at AND finished_at < expires_at AND failure_code IS NULL)
                                OR (status = 'failed' AND finished_at IS NOT NULL AND finished_at >= started_at AND failure_code IS NOT NULL)
                            )
                        )
                    )
                )
                SQL);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workout_ai_attempts');
        Schema::dropIfExists('workout_ai_analyses');
    }
};
