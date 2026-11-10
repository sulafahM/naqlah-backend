
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_university_courses', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();

            $table->foreignId('course_id')
                ->constrained('university_courses', 'course_id')
                ->cascadeOnDelete();

            $table->primary(['user_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_university_courses');
    }
};
