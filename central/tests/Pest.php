<?php

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
