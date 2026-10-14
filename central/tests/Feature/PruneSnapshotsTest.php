<?php

use App\Models\Server;
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
