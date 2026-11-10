<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmaps', function (Blueprint $table) {
            $table->id('roadmap_id');

            $table->foreignId('user_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();

            $table->foreignId('path_id')
                ->constrained('career_paths', 'path_id')
                ->cascadeOnDelete();

            $table->enum('roadmap_type', [
                'foundational',
                'personalized'
            ]);

            $table->unsignedInteger('version_no')->default(1);

            $table->enum('status', [
                'active',
                'old'
            ])->default('active');

            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmaps');
    }
};