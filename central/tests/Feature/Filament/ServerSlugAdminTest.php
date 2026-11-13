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

describe('change slug action', function () {
    it('lets an admin rename a locked slug once confirmed', function () {
        $server = lockedServer('typo-srv');
        $this->actingAs(adminUser(), 'web');

        Livewire::test(ViewServer::class, ['record' => $server->getKey()])
            ->assertActionVisible('changeSlug')
            ->callAction('changeSlug', data: ['slug' => 'good-srv', 'confirm' => true])
            ->assertHasNoActionErrors()
            ->assertNotified('Slug changé : typo-srv → good-srv');

        expect($server->refresh()->slug)->toBe('good-srv')
            ->and($server->isSlugLocked())->toBeTrue();
    });

    it('refuses without confirmation, a taken slug or a bad format', function () {
        $server = lockedServer('typo-srv');
        lockedServer('taken');
        $this->actingAs(adminUser(), 'web');
        $call = fn (array $data) => Livewire::test(ViewServer::class, ['record' => $server->getKey()])
            ->callAction('changeSlug', data: $data);

        $call(['slug' => 'good-srv', 'confirm' => false])->assertHasActionErrors(['confirm']);
        $call(['slug' => 'taken', 'confirm' => true])->assertHasActionErrors(['slug']);
        $call(['slug' => 'Bad_Slug', 'confirm' => true])->assertHasActionErrors(['slug']);

        expect($server->refresh()->slug)->toBe('typo-srv');
    });

    it('is hidden from a plain user and for a slug not yet locked', function () {
        $locked = lockedServer('typo-srv');
        $free = Server::create(['name' => 'free']);

        $this->actingAs(plainUser(), 'web');
        Livewire::test(ViewServer::class, ['record' => $locked->getKey()])->assertActionHidden('changeSlug');

        $this->actingAs(adminUser(), 'web');
        Livewire::test(ViewServer::class, ['record' => $free->getKey()])->assertActionHidden('changeSlug');
    });
});

it('shows in the server list whether each server is in the Ansible inventory', function () {
    $make = function (string $name, array $attributes, ?string $approved) {
        $server = Server::create(array_merge(['name' => $name, 'status' => Server::STATUS_ACTIVE], $attributes));

        $server->forceFill(['approved_ip' => $approved])->save();

        return $server;
    };
    $included = $make('in', ['primary_ip' => '203.0.113.1'], '203.0.113.1');
    $excluded = $make('out', ['primary_ip' => '203.0.113.2', 'ansible_excluded' => true], '203.0.113.2');
    $pending = $make('moved', ['primary_ip' => '203.0.113.9'], '203.0.113.3');
    $nowhere = $make('nowhere', [], null);
    $revoked = $make('gone', ['primary_ip' => '203.0.113.4', 'status' => Server::STATUS_REVOKED], '203.0.113.4');
    $this->actingAs(plainUser(), 'web');

    Livewire::test(ListServers::class)
        ->assertTableColumnStateSet('ansible', 'oui', $included)
        ->assertTableColumnStateSet('ansible', 'exclu', $excluded)
        ->assertTableColumnStateSet('ansible', 'IP à valider', $pending)
        ->assertTableColumnStateSet('ansible', 'sans adresse', $nowhere)
        ->assertTableColumnStateSet('ansible', 'non', $revoked);
});

it('lets an admin exclude a server from Ansible, never a plain user', function () {
    $server = Server::create(['name' => 'bastion']);

    $this->actingAs(plainUser(), 'web');
    Livewire::test(EditServer::class, ['record' => $server->getKey()])
        ->fillForm(['ansible_excluded' => true])
        ->call('save');
    expect($server->refresh()->ansible_excluded)->toBeFalse();

    $this->actingAs(adminUser(), 'web');
    Livewire::test(EditServer::class, ['record' => $server->getKey()])
        ->fillForm(['ansible_excluded' => true])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($server->refresh()->ansible_excluded)->toBeTrue();
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
