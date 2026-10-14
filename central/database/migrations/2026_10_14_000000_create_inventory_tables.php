<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('hostname')->nullable();
            $table->string('fqdn')->nullable();
            $table->string('machine_id')->nullable()->unique();
            $table->string('primary_ip', 45)->nullable();
            $table->string('os')->nullable();
            $table->string('kernel')->nullable();
            $table->string('virtualization')->nullable();
            $table->string('apache_version')->nullable();
            $table->string('mysql_version')->nullable();
            $table->string('agent_version')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_source_ip', 45)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('enrollment_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->timestamp('received_at')->index();
            $table->string('source_ip', 45)->nullable();
            $table->unsignedSmallInteger('schema_version');
            $table->json('payload');
            $table->unsignedInteger('error_count')->default(0);
            $table->json('warnings')->nullable();
            $table->timestamps();
        });

        Schema::create('server_ips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('interface');
            $table->string('address', 45)->index();
            $table->unsignedTinyInteger('prefix')->nullable();
        });

        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('server_name')->nullable()->index();
            $table->json('aliases');
            $table->unsignedInteger('port')->nullable();
            $table->boolean('https')->default(false);
            $table->dateTime('cert_expires_at')->nullable(); // TIMESTAMP stops in 2038
            $table->string('type');
            $table->string('document_root', 1024)->nullable();
            $table->string('app_dir', 1024)->nullable();
            $table->string('upstream', 1024)->nullable();
            $table->string('php_version')->nullable();
            $table->string('system_user')->nullable();
            $table->string('system_group')->nullable();
            $table->string('owner_user')->nullable();
            $table->string('owner_group')->nullable();
            $table->string('process_manager')->nullable();
            $table->string('config_file', 1024)->nullable();
            $table->boolean('partial')->default(false);
            $table->timestamps();
        });

        // "databases" is a reserved word in MySQL, hence the prefix.
        Schema::create('db_databases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();
            // Not unique: MySQL names are case-sensitive on Linux, a *_ci central is not.
            $table->index(['server_id', 'name']);
        });

        Schema::create('db_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->string('user');
            $table->string('host');
            $table->timestamps();
            $table->index(['server_id', 'user']);
        });

        Schema::create('db_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('db_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('database_id')->nullable()->constrained('db_databases')->cascadeOnDelete();
            $table->json('privileges');
            $table->boolean('global')->default(false);
        });

        Schema::create('site_db_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('db_name');
            $table->string('db_user')->nullable();
            $table->string('db_host')->nullable();
            $table->string('source', 1024)->nullable();
            // References may point at another server's database: they survive
            // that server's next snapshot and are re-linked afterwards.
            $table->foreignId('database_id')->nullable()->constrained('db_databases')->nullOnDelete();
            $table->foreignId('db_user_id')->nullable()->constrained('db_users')->nullOnDelete();
            $table->string('resolution')->default('unresolved');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['site_db_refs', 'db_grants', 'db_users', 'db_databases', 'sites',
                  'server_ips', 'snapshots', 'enrollment_tokens', 'servers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
