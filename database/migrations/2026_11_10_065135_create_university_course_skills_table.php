
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('university_course_skills', function (Blueprint $table) {
            $table->foreignId('course_id')
                ->constrained('university_courses', 'course_id')
                ->cascadeOnDelete();

            $table->foreignId('skill_id')
                ->constrained('skills', 'skill_id')
                ->cascadeOnDelete();

            $table->primary(['course_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('university_course_skills');
    }
};
