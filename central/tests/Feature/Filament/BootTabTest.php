<?php

use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Resources\Servers\RelationManagers\BootRelationManager;
use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    [$this->server] = enrolledServer();
    $this->actingAs(User::factory()->create(), 'web');
});

function bootSection(Server $server, string $name, ?array $data, ?string $error = null, array $sourceErrors = []): void
{
    $server->sections()->create([
        'section' => $name, 'collected_at' => now(), 'sha256' => $data === null ? null : str_repeat('a', 64),
        'error' => $error, 'source_errors' => $sourceErrors, 'data' => $data,
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
}

function bootTab(Server $server)
{
    return Livewire::test(BootRelationManager::class, ['ownerRecord' => $server, 'pageClass' => ViewServer::class]);
}

it('is a tab of the server sheet', function () {
    expect(ServerResource::getRelations())->toContain(BootRelationManager::class);
});

it('lists the mounted filesystems and flags the read-only ones', function () {
    bootSection($this->server, 'kernel', ['running' => '6.8.0-147-generic', 'boot_time' => '2026-11-20T06:12:00Z']);
    bootSection($this->server, 'mounts', ['filesystems' => [
        ['target' => '/', 'source' => '/dev/sda1', 'fstype' => 'ext4', 'options' => ['rw', 'relatime'], 'read_only' => false],
        ['target' => '/srv/data', 'source' => '/dev/sdb1', 'fstype' => 'xfs', 'options' => ['ro', 'relatime'], 'read_only' => true],
        'not a row',
        ['source' => '/dev/sdc1'],
    ]]);

    bootTab($this->server)
        ->assertSuccessful()
        ->assertSee('/srv/data')
        ->assertSee('/dev/sda1')
        ->assertSee('lecture seule')
        ->assertSee('1 en lecture seule')
        ->assertSee('6.8.0-147-generic')
        ->assertDontSee('/dev/sdc1');

    expect(BootRelationManager::summaryData($this->server))
        ->mounted->toBe(2)
        ->readOnly->toBe(['/srv/data'])
        ->kernel->toBe('6.8.0-147-generic');
});

it('says when the agent has not reported mounts yet', function () {
    bootTab($this->server)->assertSuccessful()->assertSee('Pas encore remonté par l’agent', false);
    expect(BootRelationManager::summaryData($this->server)['mounted'])->toBeNull();
});

it('shows why findmnt could not be read', function () {
    bootSection($this->server, 'mounts', ['filesystems' => null], sourceErrors: ['findmnt' => 'findmnt exited 1: bad usage']);

    bootTab($this->server)
        ->assertSuccessful()
        ->assertSee('Points de montage non lus', false)
        ->assertSee('findmnt exited 1: bad usage');
});

it('escapes agent text', function () {
    bootSection($this->server, 'mounts', ['filesystems' => [
        ['target' => '/<script>x</script>', 'source' => '/dev/sda1', 'fstype' => 'ext4', 'options' => ['ro'], 'read_only' => true],
    ]]);

    bootTab($this->server)->assertSuccessful()->assertDontSee('<script>x</script>', false);
});
