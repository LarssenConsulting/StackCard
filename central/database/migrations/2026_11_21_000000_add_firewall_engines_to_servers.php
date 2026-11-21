<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Firewalls filtering incoming traffic (App\Support\FirewallStatus); null = unknown.
            $table->json('firewall_engines')->nullable()->after('ufw_default_routed');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('firewall_engines');
        });
    }
};
