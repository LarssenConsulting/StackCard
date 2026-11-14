<?php

use App\Filament\Resources\Servers\Pages\ListServers;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Resources\Servers\RelationManagers\UpdatesRelationManager;
use App\Filament\Resources\Updates\Pages\ListUpdates;
use App\Filament\Resources\Updates\UpdateResource;
use App\Models\Server;
use App\Models\ServerUpdate;
use Livewire\Livewire;

function updServer(string $slug, array $state = [], array $attrs = []): Server
{
    $server = Server::create($attrs + ['name' => $slug, 'slug' => $slug, 'status' => Server::STATUS_ACTIVE]);
    $server->forceFill($state + ['updates_received_at' => now(), 'apt_cache_updated_at' => now()->subHour(),
        'updates_count' => 0, 'security_updates_count' => 0])->save();

    return $server;
}

function updRow(Server $server, string $package, string $candidate, bool $security = false, string $installed = '1.0'): ServerUpdate
{
    return ServerUpdate::create(['server_id' => $server->id, 'package' => $package, 'arch' => 'amd64',
        'installed_version' => $installed, 'candidate_version' => $candidate, 'security' => $security, 'origins' => ['Ubuntu:24.04/noble-updates']]);
}

it('shows the fleet page and the server tab to a plain user', function () {
    $a = updServer('web01', ['updates_count' => 1]);
    updRow($a, 'openssl', '3.0.2');
    $this->actingAs(plainUser(), 'web');

    $this->get(UpdateResource::getUrl())->assertOk()->assertSee('openssl');
    Livewire::test(UpdatesRelationManager::class, ['ownerRecord' => $a, 'pageClass' => ViewServer::class])
        ->assertCanSeeTableRecords($a->updates)->assertSee('openssl');
});

it('lists one openssl row for two servers, with revoked servers left out', function () {
    $a = updServer('web01', ['updates_count' => 1]);
    $b = updServer('web02', ['updates_count' => 1]);
    $gone = updServer('gone01', ['updates_count' => 1], ['status' => Server::STATUS_REVOKED]);
    updRow($a, 'openssl', '3.0.2', true, '3.0.1');
    updRow($b, 'openssl', '3.0.2', true, '3.0.0');
    updRow($gone, 'openssl', '3.0.2');
    updRow($a, 'openssl-doc', '9');
    $this->actingAs(plainUser(), 'web');

    $component = Livewire::test(ListUpdates::class);
    $rows = $component->instance()->getTableRecords();
    expect($rows->where('package', 'openssl'))->toHaveCount(1);
    $component->assertSee('>web01</a>, <a', false);
    expect($rows->where('package', 'openssl-doc'))->toHaveCount(1);
    $component->assertSeeText('3.0.0, 3.0.1 → 3.0.2')->assertSeeText('web01')->assertSeeText('web02')
        ->assertDontSeeText('gone01');
});

it('filters the fleet page on security and searches a package', function () {
    $a = updServer('web01');
    updRow($a, 'openssl', '3.0.2', true);
    updRow($a, 'tzdata', '2025b');
    $this->actingAs(plainUser(), 'web');

    $names = fn ($c) => $c->instance()->getTableRecords()->pluck('package')->all();
    expect($names(Livewire::test(ListUpdates::class)->filterTable('security', true)))->toBe(['openssl'])
        ->and($names(Livewire::test(ListUpdates::class)->filterTable('security', false)))->toBe(['tzdata'])
        ->and($names(Livewire::test(ListUpdates::class)->searchTable('tzd')))->toBe(['tzdata']);
});

it('summarises the updates in the server list', function () {
    $unknown = Server::create(['name' => 'unk', 'slug' => 'unk', 'status' => Server::STATUS_ACTIVE]);
    $ok = updServer('okay');
    $some = updServer('some', ['updates_count' => 2, 'security_updates_count' => 1, 'reboot_required' => true]);
    $plain = updServer('plain', ['updates_count' => 3]);
    $stale0 = updServer('stale0', ['apt_cache_updated_at' => now()->subDays(3)]);
    $stale2 = updServer('stale2', ['updates_count' => 2, 'apt_cache_updated_at' => now()->subDays(3)]);
    $this->actingAs(plainUser(), 'web');
    $day = now()->subDays(3)->timezone(\App\Support\DisplaySettings::timezone())->format('d/m');
    $errored = updServer('errored', ['updates_error' => 'apt verrouillé']);

    Livewire::test(ListServers::class)
        ->assertTableColumnStateSet('updates', 'inconnu', $unknown)
        ->assertTableColumnStateSet('updates', 'à jour', $ok)
        ->assertTableColumnStateSet('updates', '2 dont 1 sécu', $some)
        ->assertTableColumnStateSet('updates', '3', $plain)
        ->assertTableColumnStateSet('updates', "à jour (au {$day})", $stale0)
        ->assertTableColumnStateSet('updates', "2 (au {$day})", $stale2)
        ->assertTableColumnStateSet('updates', 'inconnu (erreur)', $errored)
        ->assertSeeText('redémarrage requis');
});

it('never paints a stale server green', function () {
    $stale0 = updServer('stale0', ['apt_cache_updated_at' => now()->subDays(3)]);
    $ok = updServer('okay');

    expect(\App\Filament\Resources\Servers\Tables\ServersTable::updatesColor($stale0))->toBe('gray')
        ->and(\App\Filament\Resources\Servers\Tables\ServersTable::updatesLabel($stale0))->not->toBe('à jour')
        ->and(\App\Filament\Resources\Servers\Tables\ServersTable::updatesColor($ok))->toBe('success');
});

it('filters the server list on security updates and reboot', function () {
    $sec = updServer('sec', ['updates_count' => 1, 'security_updates_count' => 1]);
    $boot = updServer('boot', ['reboot_required' => true]);
    $calm = updServer('calm');
    $this->actingAs(plainUser(), 'web');

    Livewire::test(ListServers::class)->filterTable('security_updates')
        ->assertCanSeeTableRecords([$sec])->assertCanNotSeeTableRecords([$boot, $calm]);
    Livewire::test(ListServers::class)->filterTable('reboot_required')
        ->assertCanSeeTableRecords([$boot])->assertCanNotSeeTableRecords([$sec, $calm]);
});

it('ignores security rows on revoked servers in the security filter', function () {
    $a = updServer('web01');
    $gone = updServer('gone01', [], ['status' => Server::STATUS_REVOKED]);
    updRow($a, 'curl', '8.0');
    updRow($gone, 'curl', '8.0', true);
    $this->actingAs(plainUser(), 'web');

    $names = fn ($c) => $c->instance()->getTableRecords()->pluck('package')->all();
    expect($names(Livewire::test(ListUpdates::class)->filterTable('security', true)))->toBe([])
        ->and($names(Livewire::test(ListUpdates::class)->filterTable('security', false)))->toBe(['curl']);
});

it('shows held and reboot-triggering packages in the server tab header', function () {
    $server = updServer('web01', ['reboot_required' => true]);
    $base = ['collected_at' => now(), 'sha256' => 'x', 'error' => null, 'source_errors' => [],
        'first_seen_at' => now(), 'last_seen_at' => now()];
    $server->sections()->create($base + ['section' => 'apt_updates', 'data' => ['upgrades' => [], 'held' => ['php8.3-fpm']]]);
    $server->sections()->create($base + ['section' => 'kernel', 'data' => ['reboot_required' => true, 'reboot_required_pkgs' => ['linux-image-6.8.0-48-generic']]]);
    $this->actingAs(plainUser(), 'web');

    Livewire::test(UpdatesRelationManager::class, ['ownerRecord' => $server, 'pageClass' => ViewServer::class])
        ->assertSeeText('redémarrage requis')
        ->assertSeeText('linux-image-6.8.0-48-generic')
        ->assertSeeText('paquets retenus : php8.3-fpm');
});

it('states the freshness and the number of updates in plain words in the server tab header', function () {
    $server = updServer('web01', ['updates_count' => 7, 'security_updates_count' => 0]);
    $this->actingAs(plainUser(), 'web');

    Livewire::test(UpdatesRelationManager::class, ['ownerRecord' => $server, 'pageClass' => ViewServer::class])
        ->assertSeeText('Données : à jour')
        ->assertSeeText('7 mises à jour en attente, aucune de sécurité')
        ->assertDontSeeText('État : 7');

    $other = updServer('web02', ['updates_count' => 3, 'security_updates_count' => 1]);
    Livewire::test(UpdatesRelationManager::class, ['ownerRecord' => $other, 'pageClass' => ViewServer::class])
        ->assertSeeText('3 mises à jour en attente, dont 1 de sécurité');

    $unknown = Server::create(['name' => 'never-reported', 'slug' => 'never-reported', 'status' => Server::STATUS_ACTIVE]);
    Livewire::test(UpdatesRelationManager::class, ['ownerRecord' => $unknown, 'pageClass' => ViewServer::class])
        ->assertSeeText('Données : inconnues')
        ->assertDontSeeText('mises à jour en attente');
});
