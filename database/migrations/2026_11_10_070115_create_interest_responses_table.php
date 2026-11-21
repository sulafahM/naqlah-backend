<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interest_responses', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();

            $table->foreignId('question_id')
                ->constrained('interest_questions', 'question_id')
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('rating')->nullable();

            $table->primary(['user_id', 'question_id']);
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            foreach (['INSERT' => 'insert', 'UPDATE' => 'update'] as $event => $name) {
                DB::statement("
                    CREATE TRIGGER interest_responses_rating_check_$name
                    BEFORE $event ON interest_responses
                    WHEN NEW.rating IS NOT NULL AND NEW.rating NOT BETWEEN 1 AND 5
                    BEGIN
                        SELECT RAISE(ABORT, 'Rating must be between 1 and 5');
                    END
                ");
            }

            return;
        }

        DB::statement('
            ALTER TABLE interest_responses
            ADD CONSTRAINT interest_responses_rating_check
            CHECK (rating IS NULL OR rating BETWEEN 1 AND 5)
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_responses');
    }
};
