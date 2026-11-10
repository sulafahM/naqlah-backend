<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_skills', function (Blueprint $table) {
            $table->id('candidate_id');

            $table->foreignId('user_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();

            $table->foreignId('skill_id')
                ->constrained('skills', 'skill_id')
                ->cascadeOnDelete();

            $table->enum('source_type', [
                'cv',
                'practical_work',
                'external_course',
                'university_course'
            ]);

            $table->unsignedBigInteger('source_id');

            $table->enum('status', [
                'pending',
                'confirmed',
                'rejected'
            ])->default('pending');

            $table->index(['user_id', 'skill_id']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_skills');
    }
};