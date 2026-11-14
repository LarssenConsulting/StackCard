<?php

use App\Models\Server;

beforeEach(function () {
    [$this->server, $this->token] = enrolledServer();
});

/** Post the fixture, with `apt_updates` rewritten by $change (and a cache one hour old by default). */
function ingestUpdates($test, ?callable $change = null): void
{
    app('auth')->forgetGuards();
    $test->withToken($test->token)->postJson('/api/v1/inventory', snapshotFixture(function (array $s) use ($change) {
        $s['sections']['apt_updates']['data']['cache_updated_at'] = now()->subHour()->toIso8601ZuluString();

        return $change ? $change($s) : $s;
    }))->assertOk();
}

it('stores the pending updates and the summary of a server', function () {
    ingestUpdates($this);

    $server = $this->server->fresh();
    $rows = $server->updates()->orderBy('package')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->package)->toBe('libssl3t64')
        ->and($rows[0]->security)->toBeTrue()
        ->and($rows[0]->origins)->toBe(['noble-security'])
        ->and($rows[0]->installed_version)->toBe('3.0.13-0ubuntu3.4')
        ->and($rows[1]->security)->toBeFalse()
        ->and($server->updates_count)->toBe(2)
        ->and($server->security_updates_count)->toBe(1)
        ->and($server->reboot_required)->toBeTrue()
        ->and($server->updates_error)->toBeNull()
        ->and($server->updatesState())->toBe('ok');
});

it('replaces the updates at each inventory instead of accumulating them', function () {
    ingestUpdates($this);
    ingestUpdates($this);
    expect($this->server->updates()->count())->toBe(2);

    ingestUpdates($this, function (array $s) {
        $s['sections']['apt_updates']['data']['upgrades'] = [$s['sections']['apt_updates']['data']['upgrades'][1]];

        return $s;
    });
    $server = $this->server->fresh();
    expect($server->updates()->pluck('package')->all())->toBe(['tzdata'])
        ->and($server->updates_count)->toBe(1)
        ->and($server->security_updates_count)->toBe(0);
});

it('keeps the previous rows and counts when the section fails, and marks the state stale', function () {
    ingestUpdates($this);

    ingestUpdates($this, function (array $s) {
        $s['sections']['apt_updates'] = [
            'collected_at' => '2026-11-01T09:00:00Z', 'sha256' => null, 'error' => 'timeout', 'source_errors' => [], 'data' => null,
        ];

        return $s;
    });

    $server = $this->server->fresh();
    expect($server->updates()->count())->toBe(2)
        ->and($server->updates_count)->toBe(2)
        ->and($server->updates_error)->toBe('timeout')
        ->and($server->updatesState())->toBe('stale');
});

it('keeps the previous rows when upgrades could not be read and records the source error', function () {
    ingestUpdates($this);

    ingestUpdates($this, function (array $s) {
        $s['sections']['apt_updates']['data']['upgrades'] = null;
        $s['sections']['apt_updates']['source_errors'] = ['upgrades' => 'exit 100'];

        return $s;
    });

    $server = $this->server->fresh();
    expect($server->updates()->count())->toBe(2)
        ->and($server->updates_error)->toBe('exit 100')
        ->and($server->updatesState())->toBe('stale');
});

it('flags a cache older than 24 hours as stale', function () {
    ingestUpdates($this, function (array $s) {
        $s['sections']['apt_updates']['data']['cache_updated_at'] = now()->subHours(30)->toIso8601ZuluString();

        return $s;
    });

    expect($this->server->fresh()->updatesState())->toBe('stale')
        ->and($this->server->updates()->count())->toBe(2);
});

it('stores the rows but marks the state stale when the agent could not read some Inst lines', function () {
    ingestUpdates($this, function (array $s) {
        $s['sections']['apt_updates']['source_errors'] = ['upgrades_unparsed' => '3 lignes non lues'];

        return $s;
    });

    $server = $this->server->fresh();
    expect($server->updates()->count())->toBe(2)
        ->and($server->updates_count)->toBe(2)
        ->and($server->updates_error)->toBe('3 lignes non lues')
        ->and($server->updatesState())->toBe('stale');
});

it('treats a cache date in the future as stale', function () {
    ingestUpdates($this, function (array $s) {
        $s['sections']['apt_updates']['data']['cache_updated_at'] = now()->addHours(3)->toIso8601ZuluString();

        return $s;
    });

    expect($this->server->fresh()->updatesState())->toBe('stale');
});

it('flags a missing cache date as stale', function () {
    ingestUpdates($this, function (array $s) {
        $s['sections']['apt_updates']['data']['cache_updated_at'] = null;

        return $s;
    });

    expect($this->server->fresh()->updatesState())->toBe('stale');
});

it('leaves a server without the apt_updates section unknown, with no counts', function () {
    ingestUpdates($this, function (array $s) {
        unset($s['sections']['apt_updates']);

        return $s;
    });

    $server = $this->server->fresh();
    expect($server->updatesState())->toBe('unknown')
        ->and($server->updates_count)->toBeNull()
        ->and($server->security_updates_count)->toBeNull()
        ->and($server->updates()->count())->toBe(0);
});

it('does not touch the updates when the section is skipped_not_due', function () {
    ingestUpdates($this);

    ingestUpdates($this, function (array $s) {
        $s['sections']['apt_updates'] = [
            'collected_at' => '2026-11-01T09:00:00Z', 'sha256' => null, 'error' => 'skipped_not_due', 'source_errors' => [], 'data' => null,
        ];

        return $s;
    });

    $server = $this->server->fresh();
    expect($server->updates()->count())->toBe(2)->and($server->updates_error)->toBeNull();
});

it('does not treat an apt_update source error alone as a failure', function () {
    ingestUpdates($this, function (array $s) {
        $s['sections']['apt_updates']['source_errors'] = ['apt_update' => 'locked'];

        return $s;
    });

    $server = $this->server->fresh();
    expect($server->updates_error)->toBeNull()
        ->and($server->updatesState())->toBe('ok');
});

it('follows reboot_required from the kernel section, including null, and ignores a failed kernel section', function () {
    ingestUpdates($this, function (array $s) {
        $s['sections']['kernel']['data']['reboot_required'] = null;

        return $s;
    });
    expect($this->server->fresh()->reboot_required)->toBeNull();

    ingestUpdates($this);
    expect($this->server->fresh()->reboot_required)->toBeTrue();

    ingestUpdates($this, function (array $s) {
        $s['sections']['kernel'] = ['collected_at' => '2026-11-01T09:00:00Z', 'sha256' => null, 'error' => 'timeout', 'source_errors' => [], 'data' => null];

        return $s;
    });
    expect($this->server->fresh()->reboot_required)->toBeTrue();
});

it('deletes the updates with the server', function () {
    ingestUpdates($this);
    $this->server->delete();

    expect(\App\Models\ServerUpdate::count())->toBe(0);
});

it('uses a fixed freshness threshold of 24 hours', function () {
    expect(Server::UPDATES_STALE_AFTER_HOURS)->toBe(24);
});

it('skips malformed entries instead of rejecting the inventory', function () {
    ingestUpdates($this, function (array $s) {
        $good = ['package' => 'curl', 'arch' => ' amd64 ', 'installed' => '8.5.0-2', 'candidate' => '8.5.0-3', 'origins' => ['noble-security', 5, ['x'], ''], 'security' => true];
        $s['sections']['apt_updates']['data']['cache_updated_at'] = 'not a date';
        $s['sections']['apt_updates']['data']['upgrades'] = [
            'garbage',
            ['arch' => 'amd64', 'candidate' => '1'],
            ['package' => ['a'], 'candidate' => '1'],
            ['package' => str_repeat('p', 300), 'candidate' => '1', 'arch' => 'amd64', 'security' => 'yes'],
            ['package' => 'nocand', 'arch' => 'amd64'],
            $good,
            ['package' => 'curl', 'arch' => 'amd64', 'candidate' => '8.5.0-4', 'installed' => ['x']],
            ['package' => 'noarch', 'candidate' => '2'],
        ];

        return $s;
    });

    $server = $this->server->fresh();
    $rows = $server->updates()->orderBy('package')->get()->keyBy('package');
    expect($rows)->toHaveCount(3)
        ->and($rows['curl']->candidate_version)->toBe('8.5.0-4')
        ->and($rows['curl']->installed_version)->toBeNull()
        ->and($rows['curl']->security)->toBeFalse()
        ->and($rows['noarch']->arch)->toBe('')
        ->and($rows[str_repeat('p', 255)]->security)->toBeFalse()
        ->and($server->updates_count)->toBe(3)
        ->and($server->apt_cache_updated_at)->toBeNull()
        ->and($server->updatesState())->toBe('stale');
});

it('keeps only the suite of an origin, even from an agent 0.6.0', function () {
    ingestUpdates($this, function (array $s) {
        $s['sections']['apt_updates']['data']['upgrades'] = [
            ['package' => 'nodejs', 'arch' => 'amd64', 'installed' => '1', 'candidate' => '2', 'origins' => ['. nodistro:nodistro'], 'security' => false],
            ['package' => 'apache2', 'arch' => 'amd64', 'installed' => '1', 'candidate' => '2', 'origins' => ['Docker:noble', 'noble-updates'], 'security' => false],
        ];

        return $s;
    });

    $origins = $this->server->updates()->orderBy('package')->pluck('origins', 'package')->all();
    expect($origins)->toBe(['apache2' => ['noble', 'noble-updates'], 'nodejs' => ['nodistro']]);
});

it('keeps only string origins, trimmed, from a good entry', function () {
    ingestUpdates($this, function (array $s) {
        $s['sections']['apt_updates']['data']['upgrades'] = [
            ['package' => 'curl', 'arch' => ' amd64 ', 'candidate' => '1', 'origins' => ['noble-security', 5, ['x'], ' '], 'security' => true],
        ];

        return $s;
    });

    $row = $this->server->updates()->sole();
    expect($row->origins)->toBe(['noble-security'])->and($row->arch)->toBe('amd64')->and($row->security)->toBeTrue();
});

it('stores a large list in chunks', function () {
    ingestUpdates($this, function (array $s) {
        $s['sections']['apt_updates']['data']['upgrades'] = array_map(
            fn (int $i) => ['package' => "pkg{$i}", 'arch' => 'amd64', 'installed' => '1', 'candidate' => '2', 'origins' => ['noble-updates'], 'security' => false],
            range(1, 1203),
        );

        return $s;
    });

    expect($this->server->updates()->count())->toBe(1203)->and($this->server->fresh()->updates_count)->toBe(1203);
});

it('records a generic error when source_errors.upgrades is not a string', function () {
    ingestUpdates($this, function (array $s) {
        $s['sections']['apt_updates']['data']['upgrades'] = null;
        $s['sections']['apt_updates']['source_errors'] = ['upgrades' => ['x']];

        return $s;
    });

    expect($this->server->fresh()->updates_error)->toBe('upgrades');
});
