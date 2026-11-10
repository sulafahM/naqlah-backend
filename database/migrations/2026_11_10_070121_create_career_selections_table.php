<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_selections', function (Blueprint $table) {
            $table->id('selection_id');

            $table->foreignId('user_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();

            $table->foreignId('path_id')
                ->constrained('career_paths', 'path_id')
                ->cascadeOnDelete();

            $table->boolean('is_current')->default(true);

            $table->index(['user_id', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_selections');
    }
};