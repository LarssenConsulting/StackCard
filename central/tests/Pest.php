<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/** The reference agent snapshot (schema v1), optionally altered by $change. */
function snapshotFixture(?callable $change = null): array
{
    $snapshot = json_decode(file_get_contents(__DIR__.'/Fixtures/snapshot.json'), true);

    return $change ? $change($snapshot) : $snapshot;
}

/** An enrolled server and the plain bearer token its agent would hold. */
function enrolledServer(string $name = 'web01', string $machineId = 'abc123'): array
{
    $server = \App\Models\Server::create([
        'name' => $name, 'machine_id' => $machineId, 'status' => \App\Models\Server::STATUS_ACTIVE,
    ]);

    return [$server, $server->createToken('agent', [\App\Models\Server::AGENT_ABILITY])->plainTextToken];
}

/** Enrolled in the authenticator app: real page requests are otherwise sent to its set-up. */
function accountWithRole(string $role): User
{
    return User::factory()->create(['role' => $role, 'app_authentication_secret' => 'test-secret']);
}

function adminUser(): User
{
    return accountWithRole(User::ROLE_ADMIN);
}

function plainUser(): User
{
    return accountWithRole(User::ROLE_USER);
}

const ALLOWED_IP = '203.0.113.10';

/** A client allowed from ALLOWED_IP and the plain token its website would hold. */
function readClient(array $ips = [ALLOWED_IP], string $name = 'site-cve'): array
{
    $client = \App\Models\ApiClient::create(['name' => $name, 'allowed_ips' => $ips]);

    return [$client, $client->issueToken()];
}

function readApi($test, string $token, string $path, string $ip = ALLOWED_IP)
{
    app('auth')->forgetGuards();

    return $test->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withToken($token)
        ->getJson("/api/v1/{$path}");
}
