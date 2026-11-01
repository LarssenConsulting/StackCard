<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('section', 32);
            $table->timestamp('collected_at')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->text('error')->nullable();
            $table->json('source_errors')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->index(['server_id', 'section', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_sections');
    }
};
