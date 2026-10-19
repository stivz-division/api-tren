<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('training_programs', function (Blueprint $table) {
            $table->dropUnique('training_programs_user_weekday_unique');
            $table->index(['user_id', 'weekday'], 'training_programs_user_weekday_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('training_programs', function (Blueprint $table) {
            $table->unique(['user_id', 'weekday'], 'training_programs_user_weekday_unique');
            $table->dropIndex('training_programs_user_weekday_index');
        });
    }
};
