
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cv_uploads', function (Blueprint $table) {
            $table->id('cv_id');

            $table->foreignId('user_id')
                ->constrained('users', 'user_id')
                ->cascadeOnDelete();

            $table->string('file_path');
            $table->text('extracted_text')->nullable();
            $table->timestamp('uploaded_at')->useCurrent();

            $table->index(['user_id', 'uploaded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cv_uploads');
    }
};
