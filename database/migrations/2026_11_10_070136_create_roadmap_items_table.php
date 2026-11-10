<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_items', function (Blueprint $table) {
            $table->id('item_id');

            $table->foreignId('roadmap_id')
                ->constrained('roadmaps', 'roadmap_id')
                ->cascadeOnDelete();

            $table->foreignId('skill_id')
                ->constrained('skills', 'skill_id')
                ->cascadeOnDelete();

            $table->string('title');

            $table->unsignedInteger('order_index');

            $table->string('resource_url')->nullable();

            $table->enum('status', [
                'pending',
                'in_progress',
                'completed'
            ])->default('pending');

            $table->unique(['roadmap_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roadmap_items');
    }
};