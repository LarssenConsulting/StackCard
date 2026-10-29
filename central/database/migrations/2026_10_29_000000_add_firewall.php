<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firewall_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('destination');
            $table->string('action', 16);
            $table->string('source');
            $table->boolean('ipv6');
            $table->string('comment')->nullable();
            $table->timestamps();
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->string('ufw_status', 16)->nullable();
            $table->string('ufw_logging', 32)->nullable();
            $table->string('ufw_default_incoming', 16)->nullable();
            $table->string('ufw_default_outgoing', 16)->nullable();
            $table->string('ufw_default_routed', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn([
                'ufw_status', 'ufw_logging', 'ufw_default_incoming', 'ufw_default_outgoing', 'ufw_default_routed',
            ]);
        });
        Schema::dropIfExists('firewall_rules');
    }
};
