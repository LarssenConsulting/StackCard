<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('approved_ip', 45)->nullable()->after('primary_ip');
        });

        // The addresses known today are trusted; only later changes need an admin.
        DB::table('servers')->whereNotNull('primary_ip')->update(['approved_ip' => DB::raw('primary_ip')]);
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('approved_ip');
        });
    }
};
