<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docker_containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('container_id', 64);
            $table->string('name');
            $table->string('image')->nullable();
            $table->string('image_version', 64)->nullable();
            $table->string('state', 32)->nullable();
            $table->json('ports');
            $table->string('compose_project')->nullable();
            $table->string('compose_service')->nullable();
            $table->string('compose_dir', 1024)->nullable();
            $table->string('restart_policy', 32)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('docker_container_id')->nullable()->constrained('docker_containers')->nullOnDelete();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->string('docker_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('docker_version');
        });
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('docker_container_id');
        });
        Schema::dropIfExists('docker_containers');
    }
};
