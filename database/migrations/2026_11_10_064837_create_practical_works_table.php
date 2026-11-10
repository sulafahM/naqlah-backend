
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practical_works', function (Blueprint $table) {
            $table->id('work_id');

            $table->foreignId('user_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();

            $table->foreignId('course_id')
                ->nullable()
                ->constrained('university_courses', 'course_id')
                ->nullOnDelete();

            $table->string('work_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('github_url')->nullable();
            $table->timestamp('submitted_at')->useCurrent();

            $table->index(['user_id', 'work_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practical_works');
    }
};
