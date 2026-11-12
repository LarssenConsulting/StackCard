<?php

use App\Support\ServerSlug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('slug', 64)->nullable()->after('name');
            $table->dateTime('slug_locked_at')->nullable()->after('slug');
            $table->string('connect_address', 255)->nullable()->after('slug_locked_at');
            $table->unsignedSmallInteger('connect_port')->nullable()->after('connect_address');
        });

        // Provisional slugs, in id order; the admin reviews them before they are locked.
        foreach (DB::table('servers')->orderBy('id')->get(['id', 'name']) as $server) {
            DB::table('servers')->where('id', $server->id)
                ->update(['slug' => ServerSlug::unique((string) $server->name, $server->id)]);
        }

        Schema::table('servers', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'slug_locked_at', 'connect_address', 'connect_port']);
        });
    }
};
