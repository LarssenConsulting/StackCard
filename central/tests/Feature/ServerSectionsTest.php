<?php

use App\Actions\IngestSnapshot;
use App\Models\Server;
use App\Models\ServerSection;
use App\Models\Snapshot;

function sectionEnvelope(array $data, array $extra = []): array
{
    return array_merge([
        'collected_at' => '2026-11-01T08:00:00Z',
        'sha256' => hash('sha256', json_encode($data)),
        'error' => null,
        'source_errors' => [],
        'data' => $data,
    ], $extra);
}

/** The reference snapshot with only the given sections. */
function withSections(array $sections): array
{
    return snapshotFixture(fn (array $s) => array_merge($s, ['sections' => $sections]));
}

function postSections($test, string $token, array $sections)
{
    return $test->withToken($token)->postJson('/api/v1/inventory', withSections($sections));
}

it('stores each section', function () {
    [$server, $token] = enrolledServer();

    $this->withToken($token)->postJson('/api/v1/inventory', snapshotFixture())->assertOk();

    $rows = $server->sections()->orderBy('id')->get();
    expect($rows->pluck('section')->all())->toBe(ServerSection::NAMES);
    $kernel = $rows->firstWhere('section', 'kernel');
    expect($kernel->data['running'])->toBe('6.8.0-45-generic')
        ->and($kernel->data['reboot_required'])->toBeTrue()
        ->and($kernel->source_errors)->toBe(['livepatch' => 'not_installed'])
        ->and($kernel->error)->toBeNull()
        ->and($kernel->collected_at->toIso8601ZuluString())->toBe('2026-11-01T08:00:00Z')
        ->and($kernel->first_seen_at)->not->toBeNull()
        ->and($kernel->sha256)->toHaveLength(64);
    expect($server->latestSections()->keys()->all())->toEqualCanonicalizing(ServerSection::NAMES);
});

it('keeps nested data intact and does not interpret it', function () {
    [$server, $token] = enrolledServer();
    $data = ['a' => ['b' => [['c' => 1, 'd' => null, 'e' => []], 'x']], 'unknown_key' => 'kept', 'empty' => []];

    postSections($this, $token, ['kernel' => sectionEnvelope($data)])->assertOk();

    expect($server->sections()->sole()->data)->toBe($data);
});

it('accepts a snapshot without sections, or with an empty sections object', function () {
    [$server, $token] = enrolledServer();

    $this->withToken($token)->postJson('/api/v1/inventory', snapshotFixture(function (array $s) {
        unset($s['sections']);

        return $s;
    }))->assertOk();
    $this->withToken($token)->postJson('/api/v1/inventory', withSections([]))->assertOk();

    expect($server->sections()->count())->toBe(0)->and($server->snapshots()->count())->toBe(2);
});

it('keeps sections out of the snapshot payload', function () {
    [$server, $token] = enrolledServer();

    $this->withToken($token)->postJson('/api/v1/inventory', snapshotFixture())->assertOk();

    expect(Snapshot::sole()->payload)->not->toHaveKey('sections')
        ->and($server->sections()->count())->toBe(14);
});

it('drops an unknown section name', function () {
    [$server, $token] = enrolledServer();

    postSections($this, $token, ['kernel' => sectionEnvelope([1]), 'bitcoin_miner' => sectionEnvelope([2])])->assertOk();

    expect($server->sections()->pluck('section')->all())->toBe(['kernel']);
});

it('refuses a malformed envelope', function (array $envelope) {
    [, $token] = enrolledServer();

    postSections($this, $token, ['kernel' => $envelope])->assertUnprocessable();
})->with([
    'sha256 not hex' => [['sha256' => 'xyz', 'data' => [], 'error' => null, 'collected_at' => '2026-11-01T08:00:00Z']],
    'error too long' => [['sha256' => null, 'data' => null, 'error' => str_repeat('x', 4001), 'collected_at' => '2026-11-01T08:00:00Z']],
    'data not an array' => [['sha256' => null, 'data' => 'text', 'error' => null, 'collected_at' => '2026-11-01T08:00:00Z']],
    'bad date' => [['sha256' => null, 'data' => [], 'error' => null, 'collected_at' => 'yesterday']],
]);

it('adds no row when sha, error and source errors are unchanged', function () {
    [$server, $token] = enrolledServer();
    $first = sectionEnvelope(['k' => 1], ['source_errors' => ['a' => 'x', 'b' => 'y']]);
    postSections($this, $token, ['kernel' => $first])->assertOk();
    $row = $server->sections()->sole();

    $this->travel(1)->hours();
    // Same content, source_errors keys in another order.
    $again = array_merge($first, ['collected_at' => '2026-11-01T09:00:00Z', 'source_errors' => ['b' => 'y', 'a' => 'x']]);
    postSections($this, $token, ['kernel' => $again])->assertOk();

    $fresh = $server->sections()->sole()->fresh();
    expect($server->sections()->count())->toBe(1)
        ->and($fresh->id)->toBe($row->id)
        ->and($fresh->collected_at->toIso8601ZuluString())->toBe('2026-11-01T09:00:00Z')
        ->and($fresh->last_seen_at->gt($row->last_seen_at))->toBeTrue()
        ->and($fresh->first_seen_at->equalTo($row->first_seen_at))->toBeTrue();
});

it('adds a row when the sha changes', function () {
    [$server, $token] = enrolledServer();
    postSections($this, $token, ['kernel' => sectionEnvelope(['k' => 1])])->assertOk();
    postSections($this, $token, ['kernel' => sectionEnvelope(['k' => 2])])->assertOk();

    expect($server->sections()->orderBy('id')->get()->map(fn ($r) => $r->data['k'])->all())->toBe([1, 2]);
});

it('adds a row when source_errors change', function () {
    [$server, $token] = enrolledServer();
    postSections($this, $token, ['kernel' => sectionEnvelope(['k' => 1])])->assertOk();
    postSections($this, $token, ['kernel' => sectionEnvelope(['k' => 1], ['source_errors' => ['nft' => 'not_installed']])])->assertOk();

    expect($server->sections()->orderBy('id')->get()->map->source_errors->all())->toBe([[], ['nft' => 'not_installed']]);
});

it('writes nothing for skipped_not_due and keeps the previous row with its date', function () {
    [$server, $token] = enrolledServer();
    postSections($this, $token, ['integrity' => sectionEnvelope([['path' => '/usr/bin/ssh']])])->assertOk();
    $before = $server->sections()->sole();

    $this->travel(1)->hours();
    postSections($this, $token, ['integrity' => ['error' => 'skipped_not_due']])->assertOk();

    $after = $server->sections()->sole()->fresh();
    expect($server->sections()->count())->toBe(1)
        ->and($after->error)->toBeNull()
        ->and($after->collected_at->equalTo($before->collected_at))->toBeTrue()
        ->and($after->last_seen_at->equalTo($before->last_seen_at))->toBeTrue();
});

it('writes nothing for skipped_not_due even when no row exists yet', function () {
    [$server, $token] = enrolledServer();

    postSections($this, $token, ['integrity' => ['error' => 'skipped_not_due']])->assertOk();

    expect($server->sections()->count())->toBe(0);
});

it('adds an error row and keeps the previous data in history', function () {
    [$server, $token] = enrolledServer();
    postSections($this, $token, ['kernel' => sectionEnvelope(['k' => 1])])->assertOk();
    postSections($this, $token, ['kernel' => [
        'collected_at' => '2026-11-01T09:00:00Z', 'error' => 'timeout', 'source_errors' => [], 'data' => null, 'sha256' => null,
    ]])->assertOk();

    $rows = $server->sections()->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->data)->toBe(['k' => 1])
        ->and($rows[1]->error)->toBe('timeout')
        ->and($rows[1]->data)->toBeNull()
        ->and($server->latestSections()['kernel']->error)->toBe('timeout');

    // The same failure again adds nothing.
    postSections($this, $token, ['kernel' => [
        'collected_at' => '2026-11-01T10:00:00Z', 'error' => 'timeout', 'source_errors' => [], 'data' => null, 'sha256' => null,
    ]])->assertOk();
    expect($server->sections()->count())->toBe(2);
});

it('records an oversized section as too_large without refusing the snapshot', function () {
    [$server, $token] = enrolledServer();
    $big = ['blob' => str_repeat('a', ServerSection::MAX_BYTES + 1)];

    postSections($this, $token, ['packages' => sectionEnvelope($big), 'kernel' => sectionEnvelope(['k' => 1])])
        ->assertOk()->assertExactJson(['status' => 'ok']);

    $row = $server->sections()->where('section', 'packages')->sole();
    expect($row->error)->toBe('too_large')->and($row->data)->toBeNull()->and($row->sha256)->toBeNull()
        ->and($server->sections()->where('section', 'kernel')->sole()->data)->toBe(['k' => 1]);
});

it('still answers status ok only', function () {
    [, $token] = enrolledServer();

    $this->withToken($token)->postJson('/api/v1/inventory', snapshotFixture())->assertOk()->assertExactJson(['status' => 'ok']);
});

it('validates a large section in reasonable time', function () {
    [$server, $token] = enrolledServer();
    $rows = array_map(fn ($i) => ['name' => "pkg{$i}", 'version' => '1.0', 'arch' => 'amd64', 'status' => 'ii'], range(1, 8000));

    $start = microtime(true);
    postSections($this, $token, ['packages' => sectionEnvelope($rows)])->assertOk();

    expect(microtime(true) - $start)->toBeLessThan(5.0)
        ->and(count($server->sections()->sole()->data))->toBe(8000);
});

it('rolls back sections with the snapshot', function () {
    [$server] = enrolledServer();
    $snapshot = snapshotFixture();

    // A failure after the sections are written must undo them.
    $failing = new class(app(\App\Actions\LinkDatabases::class)) extends \App\Actions\LinkDatabases
    {
        public function __invoke(): void
        {
            throw new RuntimeException('boom');
        }
    };
    expect(fn () => (new IngestSnapshot($failing))($server, $snapshot, '10.0.0.1'))->toThrow(RuntimeException::class);

    expect(ServerSection::count())->toBe(0)->and(Snapshot::count())->toBe(0);
});

it('deletes sections with the server', function () {
    [$server, $token] = enrolledServer();
    $this->withToken($token)->postJson('/api/v1/inventory', snapshotFixture())->assertOk();
    expect(ServerSection::count())->toBe(14);

    $server->delete();

    expect(ServerSection::count())->toBe(0);
});

it('keeps sections of other servers apart', function () {
    [$one, $tokenOne] = enrolledServer('one', 'm1');
    [$two, $tokenTwo] = enrolledServer('two', 'm2');
    postSections($this, $tokenOne, ['kernel' => sectionEnvelope(['k' => 1])])->assertOk();
    app('auth')->forgetGuards();
    postSections($this, $tokenTwo, ['kernel' => sectionEnvelope(['k' => 1])])->assertOk();

    expect($one->sections()->count())->toBe(1)->and($two->sections()->count())->toBe(1);
});

/** The agent's canonical form: keys sorted, compact separators, UTF-8 not escaped. */
function canonicalSha(mixed $data): string
{
    $sort = function (mixed $item) use (&$sort) {
        if (! is_array($item)) {
            return $item;
        }
        $item = array_map($sort, $item);
        if (! array_is_list($item)) {
            ksort($item);
        }

        return $item;
    };

    return hash('sha256', json_encode($sort($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

it('stores data and source errors exactly as sent: empty strings, padding and dotted keys', function () {
    [$server, $token] = enrolledServer();
    $data = [
        'sysctl' => ['net.mptcp.enabled' => null, 'net.ipv4.ip_forward' => '0'],
        'php' => ['disable_functions' => '', 'session.save_path' => '', 'open_basedir' => 'no value'],
        'cron' => ['  indented # cron  ', "tab\t"],
        'accents' => 'été / ✓',
    ];
    $sourceErrors = ['8.3.fpm' => 'not_installed', 'blank' => '', 'padded' => '  oops '];
    $envelope = sectionEnvelope($data, ['sha256' => canonicalSha($data), 'source_errors' => $sourceErrors]);

    postSections($this, $token, ['kernel_surface' => $envelope])->assertOk();

    $row = $server->sections()->sole();
    expect($row->data)->toBe($data)
        ->and($row->source_errors)->toBe($sourceErrors)
        ->and($row->data['php']['disable_functions'])->toBe('')
        ->and(canonicalSha($row->data))->toBe($row->sha256);
});

it('still turns an empty string outside the sections into null', function () {
    [$server, $token] = enrolledServer();

    $this->withToken($token)->postJson('/api/v1/inventory', snapshotFixture(function (array $s) {
        $s['server']['fqdn'] = '';

        return $s;
    }))->assertOk();

    expect($server->refresh()->fqdn)->toBeNull();
});

it('requires sha256 when data is present', function () {
    [, $token] = enrolledServer();

    postSections($this, $token, ['kernel' => sectionEnvelope(['k' => 1], ['sha256' => null])])->assertUnprocessable();
});

it('refuses more than 500 source errors', function () {
    [, $token] = enrolledServer();
    $errors = array_combine(array_map(fn ($i) => "s{$i}", range(1, 501)), array_fill(0, 501, 'x'));

    postSections($this, $token, ['kernel' => sectionEnvelope(['k' => 1], ['source_errors' => $errors])])->assertUnprocessable();
});

it('adds a new row when a section recovers from an error back to an older sha', function () {
    [$server, $token] = enrolledServer();
    $good = sectionEnvelope(['k' => 1]);
    $failed = ['collected_at' => '2026-11-01T09:00:00Z', 'error' => 'timeout', 'source_errors' => [], 'data' => null, 'sha256' => null];

    postSections($this, $token, ['kernel' => $good])->assertOk();
    postSections($this, $token, ['kernel' => $failed])->assertOk();
    postSections($this, $token, ['kernel' => $good])->assertOk();

    $rows = $server->sections()->orderBy('id')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('error')->all())->toBe([null, 'timeout', null])
        ->and($server->latestSections()['kernel']->data)->toBe(['k' => 1]);
});

it('adds one too_large row, not one per send', function () {
    [$server, $token] = enrolledServer();
    $big = sectionEnvelope(['blob' => str_repeat('a', ServerSection::MAX_BYTES + 1)]);

    postSections($this, $token, ['packages' => $big])->assertOk();
    postSections($this, $token, ['packages' => $big])->assertOk();

    expect($server->sections()->count())->toBe(1)->and($server->sections()->sole()->error)->toBe('too_large');
});
