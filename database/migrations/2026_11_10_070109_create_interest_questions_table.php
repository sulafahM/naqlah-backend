<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interest_questions', function (Blueprint $table) {
            $table->id('question_id');

            $table->foreignId('path_id')
                ->constrained('career_paths', 'path_id')
                ->cascadeOnDelete();

            $table->text('question_text');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_questions');
    }
};