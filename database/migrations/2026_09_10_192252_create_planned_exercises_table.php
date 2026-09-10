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
        Schema::create('planned_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sets');
            $table->unsignedInteger('repetitions_per_set');
            $table->unsignedBigInteger('working_weight_grams');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['training_program_id', 'exercise_id']);
            $table->unique(['training_program_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planned_exercises');
    }
};
