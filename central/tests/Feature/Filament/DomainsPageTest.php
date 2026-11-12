<?php

use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Resources\Domains\Pages\ListDomains;
use App\Models\Server;
use Livewire\Livewire;

function ingestDomains(string $token): void
{
    app('auth')->forgetGuards();
    test()->withToken($token)->postJson('/api/v1/inventory', snapshotFixture())->assertOk();
}

it('shows the domains page to a plain user', function () {
    [$server, $token] = enrolledServer();
    ingestDomains($token);
    $this->actingAs(plainUser(), 'web');

    $this->get(DomainResource::getUrl())->assertOk()->assertSee('Domaines');
});

it('searches domains, aliases included', function () {
    [$server, $token] = enrolledServer();
    ingestDomains($token);
    $this->actingAs(plainUser(), 'web');

    $component = Livewire::test(ListDomains::class)->searchTable('shop');
    $component->assertSeeText('shop.example.com')->assertSeeText('www.shop.example.com');
    $names = $component->instance()->getTableRecords()->pluck('domain')->all();
    expect($names)->each->toContain('shop');

    $other = Livewire::test(ListDomains::class)->searchTable('shop');
    expect($other->instance()->getTableRecords()->pluck('domain')->all())->not->toContain('unrelated.example.org');
});

it('lists one row per domain with its ports', function () {
    [$server, $token] = enrolledServer();
    ingestDomains($token);
    $this->actingAs(plainUser(), 'web');

    $component = Livewire::test(ListDomains::class);
    $domains = $component->instance()->getTableRecords()->pluck('domain');
    expect($domains->filter(fn ($d) => $d === 'shop.example.com'))->toHaveCount(1);
    $component->assertSeeText('80, 443');
});

it('filters by server and leaves revoked servers out of the options', function () {
    [$server, $token] = enrolledServer();
    ingestDomains($token);
    $revoked = Server::create(['name' => 'gone-server', 'status' => Server::STATUS_REVOKED]);
    $this->actingAs(plainUser(), 'web');

    Livewire::test(ListDomains::class)
        ->filterTable('server_id', $server->id)
        ->assertSeeText('shop.example.com')
        ->assertSee($server->name);
    expect(collect(Livewire::test(ListDomains::class)->instance()->getTable()->getFilter('server_id')->getOptions())->values())
        ->not->toContain('gone-server');
});

it('leaves out the domains of a revoked server', function () {
    [$server, $token] = enrolledServer();
    ingestDomains($token);
    $second = Server::create(['name' => 'second', 'status' => Server::STATUS_ACTIVE]);
    $site = $second->sites()->create(['server_name' => 'visible.example.net', 'aliases' => [], 'port' => 80, 'https' => false, 'type' => 'static']);
    \App\Models\SiteDomain::create([
        'site_id' => $site->id, 'server_id' => $second->id, 'domain' => 'visible.example.net', 'role' => 'server_name',
    ]);
    $this->actingAs(plainUser(), 'web');
    $server->update(['status' => Server::STATUS_REVOKED]);

    Livewire::test(ListDomains::class)
        ->assertDontSeeText('shop.example.com')
        ->assertSeeText('visible.example.net');
});
