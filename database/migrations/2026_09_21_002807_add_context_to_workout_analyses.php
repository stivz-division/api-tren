<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workout_analyses', function (Blueprint $table): void {
            $table->jsonb('context')->nullable();
            $table->unsignedSmallInteger('context_version')->nullable();
        });
        Schema::table('workout_sessions', function (Blueprint $table): void {
            $table->index(['user_id', 'status', 'training_program_id', 'completed_at', 'id'], 'workout_sessions_same_program_context_index');
            $table->index(['user_id', 'status', 'completed_at', 'id'], 'workout_sessions_other_programs_context_index');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                ALTER TABLE workout_analyses ADD CONSTRAINT workout_analysis_context_check
                CHECK (
                    (context IS NULL AND context_version IS NULL)
                    OR (context IS NOT NULL AND context_version IS NOT NULL AND context_version > 0 AND jsonb_typeof(context) = 'object')
                )
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE workout_analyses DROP CONSTRAINT workout_analysis_context_check');
        }
        Schema::table('workout_analyses', function (Blueprint $table): void {
            $table->dropColumn(['context', 'context_version']);
        });
        Schema::table('workout_sessions', function (Blueprint $table): void {
            $table->dropIndex('workout_sessions_same_program_context_index');
            $table->dropIndex('workout_sessions_other_programs_context_index');
        });
    }
};
