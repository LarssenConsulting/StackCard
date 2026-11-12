<?php

use App\Models\SiteDomain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->constrained()->cascadeOnDelete();
            // Binary collation (MySQL): accent and ß variants are distinct names, so they cannot collide on the unique key.
            $domain = $table->string('domain', 255);
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $domain->collation('utf8mb4_bin');
            }
            $domain->index();
            $table->string('role', 16);
            $table->timestamps();

            $table->unique(['site_id', 'domain']);
        });

        $now = now();
        DB::table('sites')->orderBy('id')->chunkById(200, function ($sites) use ($now) {
            $rows = [];
            foreach ($sites as $site) {
                $aliases = json_decode((string) $site->aliases, true);
                foreach (SiteDomain::namesFor($site->server_name, is_array($aliases) ? $aliases : []) as $domain => $role) {
                    $rows[] = [
                        'site_id' => $site->id, 'server_id' => $site->server_id, 'domain' => $domain,
                        'role' => $role, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
            if ($rows) {
                DB::table('site_domains')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_domains');
    }
};
