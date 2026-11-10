
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_paths', function (Blueprint $table) {
            $table->id('path_id');
            $table->string('name')->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_paths');
    }
};
