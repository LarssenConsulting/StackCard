<?php

use Illuminate\Support\Facades\DB;

/**
 * Production runs on MySQL/MariaDB, tests on SQLite, which ignores varchar
 * lengths and TIMESTAMP's 2038 limit. Compile the migration with the MySQL
 * grammar (pretend mode, no server needed) to check what production gets.
 */
function inventoryMysqlSql(): string
{
    config(['database.connections.mysql_pretend' => [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'discover',
        'username' => 'x', 'password' => '', 'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
    ]]);
    $migration = include database_path('migrations/2026_10_14_000000_create_inventory_tables.php');
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('mysql_pretend');

    try {
        $queries = DB::connection('mysql_pretend')->pretend(fn () => $migration->up());
    } finally {
        DB::setDefaultConnection($default);
    }

    return implode("\n", array_column($queries, 'query'));
}

it('stores certificate expiry dates beyond 2038', function () {
    expect(inventoryMysqlSql())
        ->toContain('`cert_expires_at` datetime null')
        ->not->toContain('`cert_expires_at` timestamp');
});

it('gives path columns room for every length the API accepts', function (string $column) {
    expect(inventoryMysqlSql())->toContain("`{$column}` varchar(1024)");
})->with(['document_root', 'app_dir', 'upstream', 'config_file', 'source']);

it('does not rely on case-sensitive unique indexes for agent-reported names', function () {
    // MySQL on Linux allows databases "Shop" and "shop", or users "App"@"%" and "app"@"%";
    // a *_ci central collation would see them as duplicates and fail the whole ingest.
    expect(inventoryMysqlSql())
        ->not->toContain('db_databases_server_id_name_unique')
        ->not->toContain('db_users_server_id_user_host_unique');
});
