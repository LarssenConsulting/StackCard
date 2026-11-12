<?php

use App\Filament\Resources\Servers\Pages\CreateServer;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Servers\Pages\ListServers;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Models\Server;
use Livewire\Livewire;

function lockedServer(string $name = 'locked'): Server
{
    $server = Server::create(['name' => $name, 'status' => Server::STATUS_ACTIVE, 'last_seen_at' => now()]);
    $server->forceFill(['slug_locked_at' => now()])->save();

    return $server;
}

describe('slug form', function () {
    beforeEach(fn () => $this->actingAs(adminUser(), 'web'));

    it('prefills the slug from the name on creation', function () {
        Livewire::test(CreateServer::class)
            ->set('data.name', 'Web 01')
            ->assertSet('data.slug', 'web-01')
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Server::where('name', 'Web 01')->value('slug'))->toBe('web-01');
    });

    it('rejects a malformed or already used slug', function () {
        Server::create(['name' => 'Taken', 'slug' => 'taken']);

        Livewire::test(CreateServer::class)
            ->fillForm(['name' => 'X', 'slug' => 'Web_01'])->call('create')
            ->assertHasFormErrors(['slug']);
        Livewire::test(CreateServer::class)
            ->fillForm(['name' => 'X', 'slug' => 'taken'])->call('create')
            ->assertHasFormErrors(['slug' => 'Ce slug est déjà pris.']);
    });

    it('lets an admin change an unlocked slug but not a locked one', function () {
        $free = Server::create(['name' => 'free']);
        $locked = lockedServer();

        Livewire::test(EditServer::class, ['record' => $free->getKey()])
            ->fillForm(['slug' => 'renamed'])->call('save')->assertHasNoFormErrors();
        Livewire::test(EditServer::class, ['record' => $locked->getKey()])
            ->fillForm(['slug' => 'renamed-too'])->call('save');

        expect($free->refresh()->slug)->toBe('renamed')
            ->and($locked->refresh()->slug)->toBe('locked');
    });

    it('validates the connection address and port', function () {
        $server = Server::create(['name' => 'conn']);

        Livewire::test(EditServer::class, ['record' => $server->getKey()])
            ->fillForm(['connect_address' => 'not an address!'])->call('save')
            ->assertHasFormErrors(['connect_address']);
        Livewire::test(EditServer::class, ['record' => $server->getKey()])
            ->fillForm(['connect_port' => 70000])->call('save')
            ->assertHasFormErrors(['connect_port']);

        foreach (['10.0.0.5', 'web01.lan'] as $address) {
            Livewire::test(EditServer::class, ['record' => $server->getKey()])
                ->fillForm(['connect_address' => $address, 'connect_port' => 2222])->call('save')
                ->assertHasNoFormErrors();
            expect($server->refresh()->connect_address)->toBe($address)
                ->and($server->connect_port)->toBe(2222);
        }
    });

    it('shows the slug and the unlocked badge', function () {
        $server = Server::create(['name' => 'shown']);

        Livewire::test(ViewServer::class, ['record' => $server->getKey()])->assertSee('shown')->assertSee('non figé');
        Livewire::test(ListServers::class)->assertSee('non figé');
    });
});

it('ignores a slug and an address submitted by a non-admin', function () {
    $server = Server::create(['name' => 'plain', 'connect_address' => '10.0.0.1']);
    $this->actingAs(plainUser(), 'web');

    Livewire::test(EditServer::class, ['record' => $server->getKey()])
        ->fillForm(['name' => 'plain2', 'slug' => 'hijacked', 'connect_address' => '10.9.9.9', 'connect_port' => 22])
        ->call('save');

    $server->refresh();
    expect($server->name)->toBe('plain2')
        ->and($server->slug)->toBe('plain')
        ->and($server->connect_address)->toBe('10.0.0.1')
        ->and($server->connect_port)->toBeNull();
});

describe('lock slugs action', function () {
    it('is hidden for a plain user', function () {
        Server::create(['name' => 'seen', 'last_seen_at' => now()]);
        $this->actingAs(plainUser(), 'web');

        Livewire::test(ListServers::class)->assertActionHidden('lockSlugs');
    });

    it('is hidden when nothing is lockable', function () {
        lockedServer();
        Server::create(['name' => 'pending']);
        $this->actingAs(adminUser(), 'web');

        Livewire::test(ListServers::class)->assertActionHidden('lockSlugs');
    });

    it('locks the servers already seen and keeps the pending one editable', function () {
        $seen = Server::create(['name' => 'seen', 'last_seen_at' => now()]);
        $pending = Server::create(['name' => 'pending']);
        $this->actingAs(adminUser(), 'web');

        Livewire::test(ListServers::class)
            ->assertActionVisible('lockSlugs')
            ->callAction('lockSlugs')
            ->assertNotified('1 slug figé');

        expect($seen->refresh()->isSlugLocked())->toBeTrue()
            ->and($pending->refresh()->isSlugLocked())->toBeFalse();
    });
});

it('lets a non-admin create a server whose slug collides: it is auto-suffixed', function () {
    Server::create(['name' => 'web01', 'slug' => 'web01']);
    $this->actingAs(plainUser(), 'web');

    Livewire::test(CreateServer::class)
        ->fillForm(['name' => 'web01'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Server::where('name', 'web01')->orderBy('id')->get()->pluck('slug')->all())->toBe(['web01', 'web01-2']);
});
