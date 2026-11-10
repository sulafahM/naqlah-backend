<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_path_skills', function (Blueprint $table) {
            $table->foreignId('path_id')
                ->constrained('career_paths', 'path_id')
                ->cascadeOnDelete();

            $table->foreignId('skill_id')
                ->constrained('skills', 'skill_id')
                ->cascadeOnDelete();

            $table->boolean('is_foundational')->default(false);

            $table->primary(['path_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_path_skills');
    }
};