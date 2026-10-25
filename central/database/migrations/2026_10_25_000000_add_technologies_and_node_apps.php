<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('version', 32)->nullable();
            $table->string('node_version', 32)->nullable();
            $table->json('listen');
            $table->string('user')->nullable();
            $table->string('group')->nullable();
            $table->string('app_dir', 1024)->nullable();
            $table->string('process_manager')->nullable();
            $table->json('technologies');
            $table->timestamps();
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->json('technologies')->nullable();
            $table->foreignId('node_app_id')->nullable()->constrained('node_apps')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('node_app_id');
            $table->dropColumn('technologies');
        });
        Schema::dropIfExists('node_apps');
    }
};
