
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_aliases', function (Blueprint $table) {
            $table->id('alias_id');

            $table->foreignId('skill_id')
                ->constrained('skills', 'skill_id')
                ->cascadeOnDelete();

            $table->string('alias');

            $table->unique(['skill_id', 'alias']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_aliases');
    }
};
