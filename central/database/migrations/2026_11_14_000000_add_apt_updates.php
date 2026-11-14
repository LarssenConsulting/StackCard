<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            // Binary collation (MySQL): package and arch are compared byte for byte, like the PHP deduplication.
            $package = $table->string('package', 255);
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $package->collation('utf8mb4_bin');
            }
            $package->index();
            $arch = $table->string('arch', 32);
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $arch->collation('utf8mb4_bin');
            }
            $table->string('installed_version', 255)->nullable();
            $candidate = $table->string('candidate_version', 255);
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $candidate->collation('utf8mb4_bin');
            }
            $table->boolean('security')->default(false);
            $table->json('origins');
            $table->timestamps();

            $table->unique(['server_id', 'package', 'arch']);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->unsignedInteger('updates_count')->nullable();
            $table->unsignedInteger('security_updates_count')->nullable();
            $table->dateTime('apt_cache_updated_at')->nullable();
            $table->dateTime('updates_received_at')->nullable();
            $table->string('updates_error', 4000)->nullable();
            $table->boolean('reboot_required')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn([
                'updates_count', 'security_updates_count', 'apt_cache_updated_at',
                'updates_received_at', 'updates_error', 'reboot_required',
            ]);
        });
        Schema::dropIfExists('server_updates');
    }
};
