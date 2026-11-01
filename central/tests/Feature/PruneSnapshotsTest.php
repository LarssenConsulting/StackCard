<?php

use App\Models\Server;
use App\Models\ServerSection;
use App\Models\Snapshot;
use Illuminate\Console\Scheduling\Schedule;

function snapshotAt(Server $server, string $when): Snapshot
{
    return $server->snapshots()->create([
        'received_at' => now()->parse($when), 'schema_version' => 1, 'payload' => [], 'error_count' => 0,
    ]);
}

it('deletes snapshots older than 30 days', function () {
    $server = Server::create(['name' => 'web01']);
    $old = snapshotAt($server, '-40 days');
    $recent = snapshotAt($server, '-2 days');

    $this->artisan('discover:prune-snapshots')->assertSuccessful();

    expect(Snapshot::pluck('id')->all())->toBe([$recent->id]);
    expect(Snapshot::find($old->id))->toBeNull();
});

it('always keeps the latest snapshot of each server', function () {
    $silent = Server::create(['name' => 'silent']);
    snapshotAt($silent, '-90 days');
    $latest = snapshotAt($silent, '-60 days');

    $this->artisan('discover:prune-snapshots')->assertSuccessful();

    expect(Snapshot::pluck('id')->all())->toBe([$latest->id]);
});

it('is scheduled daily', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'discover:prune-snapshots'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 3 * * *');
});

function sectionAt(Server $server, string $section, string $when, ?array $data = ['k' => 1], ?string $error = null): ServerSection
{
    return $server->sections()->create([
        'section' => $section, 'collected_at' => now()->parse($when), 'sha256' => $data ? str_repeat('a', 64) : null,
        'error' => $error, 'source_errors' => [], 'data' => $data,
        'first_seen_at' => now()->parse($when), 'last_seen_at' => now()->parse($when),
    ]);
}

it('removes section rows older than 30 days', function () {
    $server = Server::create(['name' => 'web01']);
    $old = sectionAt($server, 'kernel', '-50 days');
    $recentOld = sectionAt($server, 'kernel', '-40 days');
    $latest = sectionAt($server, 'kernel', '-2 days');

    $this->artisan('discover:prune-snapshots')->assertSuccessful();

    expect(ServerSection::orderBy('id')->pluck('id')->all())->toBe([$latest->id]);
});

it('keeps the latest section row and the latest row with data of each server and section', function () {
    $one = Server::create(['name' => 'one']);
    $two = Server::create(['name' => 'two']);
    $oldDataOlder = sectionAt($one, 'kernel', '-70 days');
    $oldData = sectionAt($one, 'kernel', '-60 days');
    $oldFailure = sectionAt($one, 'kernel', '-50 days', null, 'timeout');
    $latestFailure = sectionAt($one, 'kernel', '-45 days', null, 'timeout');
    $otherSection = sectionAt($one, 'packages', '-80 days');
    $otherServer = sectionAt($two, 'kernel', '-90 days');

    $this->artisan('discover:prune-snapshots')->assertSuccessful();

    // Per (server, section): the latest row, and the latest row with data.
    expect(ServerSection::orderBy('id')->pluck('id')->all())
        ->toBe([$oldData->id, $latestFailure->id, $otherSection->id, $otherServer->id]);
});
