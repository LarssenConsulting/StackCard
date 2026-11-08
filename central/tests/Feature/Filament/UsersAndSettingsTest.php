<?php

use App\Filament\Pages\Settings;
use App\Filament\Resources\ApiClients\ApiClientResource;
use App\Filament\Resources\ApiClients\Pages\ListApiClients;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Servers\Pages\ListServers;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Resources\Servers\RelationManagers\SnapshotsRelationManager;
use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\ApiClient;
use App\Models\Server;
use App\Models\User;
use App\Support\DisplaySettings;
use Filament\Auth\Pages\EditProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

/** Enrolled in the authenticator app: real page requests are otherwise sent to its set-up. */
function accountWithRole(string $role): User
{
    return User::factory()->create(['role' => $role, 'app_authentication_secret' => 'test-secret']);
}

function adminUser(): User
{
    return accountWithRole(User::ROLE_ADMIN);
}

function plainUser(): User
{
    return accountWithRole(User::ROLE_USER);
}

describe('roles', function () {
    it('makes the very first account an admin and the next ones plain users', function () {
        expect(User::factory()->create()->isAdmin())->toBeTrue()
            ->and(User::factory()->create()->refresh()->isAdmin())->toBeFalse();
    });

    it('keeps the role asked for, even on the first account', function () {
        expect(User::factory()->create(['role' => User::ROLE_USER])->isAdmin())->toBeFalse();
    });

    it('turns the existing accounts into admins when migrating', function () {
        $migration = require database_path('migrations/2026_11_01_200000_add_roles_and_settings.php');
        $migration->down();
        $id = DB::table('users')->insertGetId([
            'name' => 'old', 'email' => 'old@example.org', 'password' => 'x',
        ]);

        $migration->up();

        expect(User::find($id)->isAdmin())->toBeTrue();
    });
});

describe('a plain user', function () {
    beforeEach(function () {
        adminUser();
        $this->actingAs(plainUser(), 'web');
    });

    it('still reads the inventory', function () {
        $this->get(ServerResource::getUrl())->assertOk();
    });

    it('cannot open the settings, the accounts or the API clients', function () {
        $this->get(Settings::getUrl())->assertForbidden();
        $this->get(UserResource::getUrl())->assertForbidden();
        $this->get(UserResource::getUrl('create'))->assertForbidden();
        $this->get(UserResource::getUrl('edit', ['record' => User::first()]))->assertForbidden();
        $this->get(ApiClientResource::getUrl())->assertForbidden();
        $this->get(ApiClientResource::getUrl('create'))->assertForbidden();
    });

    it('does not see them in the menu', function () {
        $this->get(ServerResource::getUrl())
            ->assertDontSee(UserResource::getUrl(), false)
            ->assertDontSee(Settings::getUrl(), false)
            ->assertDontSee(ApiClientResource::getUrl(), false);
    });

    it('cannot save the settings', function () {
        Livewire::test(Settings::class)->assertForbidden();
    });

    it('cannot generate an enrollment token', function () {
        [$server] = enrolledServer();

        Livewire::test(ViewServer::class, ['record' => $server->id])->assertActionHidden('generateEnrollmentToken');
        Livewire::test(EditServer::class, ['record' => $server->id])->assertActionHidden('generateEnrollmentToken');
        Livewire::test(ListServers::class)->assertTableActionHidden('generateEnrollmentToken', $server);
    });

    it('cannot give itself the admin role from its profile', function () {
        $user = auth()->user();

        Livewire::test(EditProfile::class)
            ->set('data.role', User::ROLE_ADMIN)
            ->call('save');

        expect($user->refresh()->isAdmin())->toBeFalse();
    });
});

describe('an admin', function () {
    beforeEach(function () {
        $this->admin = adminUser();
        $this->actingAs($this->admin, 'web');
    });

    it('can open the settings, the accounts and the API clients', function () {
        $this->get(Settings::getUrl())->assertOk();
        $this->get(UserResource::getUrl())->assertOk();
        $this->get(ApiClientResource::getUrl())->assertOk();
    });

    it('finds them under Réglages in the menu', function () {
        $this->get(ServerResource::getUrl())
            ->assertSee('Réglages')
            ->assertSee(UserResource::getUrl(), false)
            ->assertSee(Settings::getUrl(), false)
            ->assertSee(ApiClientResource::getUrl(), false);
    });

    it('sees every account', function () {
        $other = plainUser();

        Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$this->admin, $other]);
    });

    it('creates an account with a role and a hashed password', function () {
        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Alice', 'email' => 'alice@example.org', 'role' => User::ROLE_USER, 'password' => 'un-mot-de-passe-long'])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'alice@example.org')->sole();
        expect($created->isAdmin())->toBeFalse()
            ->and(Hash::check('un-mot-de-passe-long', $created->password))->toBeTrue();
    });

    it('refuses a short password, a taken email or an unknown role', function () {
        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'A', 'email' => $this->admin->email, 'role' => 'root', 'password' => 'court'])
            ->call('create')
            ->assertHasFormErrors(['email', 'role', 'password']);
    });

    it('changes the role of another account, and keeps its password when left empty', function () {
        $other = plainUser();
        $hash = $other->password;

        Livewire::test(EditUser::class, ['record' => $other->id])
            ->fillForm(['role' => User::ROLE_ADMIN])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($other->refresh()->isAdmin())->toBeTrue()->and($other->password)->toBe($hash);
    });

    it('cannot remove its own admin role', function () {
        Livewire::test(EditUser::class, ['record' => $this->admin->id])
            ->fillForm(['role' => User::ROLE_USER])
            ->call('save');

        expect($this->admin->refresh()->isAdmin())->toBeTrue();
    });

    it('cannot delete its own account', function () {
        Livewire::test(EditUser::class, ['record' => $this->admin->id])->assertActionHidden('delete');
        Livewire::test(ListUsers::class)->assertTableActionHidden('delete', $this->admin);
    });

    it('deletes another account', function () {
        $other = plainUser();

        Livewire::test(ListUsers::class)->callTableAction('delete', $other);

        expect(User::find($other->id))->toBeNull();
    });

    it('generates an enrollment token', function () {
        [$server] = enrolledServer();

        Livewire::test(ViewServer::class, ['record' => $server->id])->assertActionVisible('generateEnrollmentToken');
    });

    it('saves the settings', function () {
        Livewire::test(Settings::class)
            ->fillForm(['timezone' => 'America/New_York', 'date_format' => 'Y-m-d'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(DisplaySettings::timezone())->toBe('America/New_York')
            ->and(DisplaySettings::dateFormat())->toBe('Y-m-d')
            ->and(DisplaySettings::dateTimeFormat())->toBe('Y-m-d H:i');
    });

    it('refuses an unknown timezone or date format', function () {
        Livewire::test(Settings::class)
            ->fillForm(['timezone' => 'Mars/Olympus', 'date_format' => 'nope'])
            ->call('save')
            ->assertHasFormErrors(['timezone', 'date_format']);

        expect(DisplaySettings::timezone())->toBe('Europe/Paris');
    });

    it('shows dates in the chosen timezone and format, and stores them untouched', function () {
        [$server] = enrolledServer();
        $snapshot = $server->snapshots()->create(['received_at' => '2026-11-01 08:30:00', 'schema_version' => 1, 'payload' => []]);

        $component = fn () => Livewire::test(SnapshotsRelationManager::class, ['ownerRecord' => $server, 'pageClass' => ViewServer::class]);

        // Paris is UTC+1 on 1 November.
        $component()->assertSee('01/11/2026 09:30')->assertDontSee('08:30');

        DisplaySettings::save('America/New_York', 'Y-m-d');
        $component()->assertSee('2026-11-01 03:30');

        expect($snapshot->refresh()->getRawOriginal('received_at'))->toBe('2026-11-01 08:30:00');
    });

    it('shows the expiry of an enrollment token in the chosen timezone', function () {
        $server = Server::create(['name' => 'new', 'status' => Server::STATUS_PENDING]);
        $server->enrollmentTokens()->create(['token_hash' => str_repeat('a', 64), 'expires_at' => '2026-11-02 08:30:00']);
        $this->travelTo('2026-11-01 08:30:00');

        $this->get(ServerResource::getUrl('view', ['record' => $server]))->assertSee('02/11/2026 à 09:30');
    });
});

it('falls back to Paris when nothing was saved or a stored value is no longer valid', function () {
    expect(DisplaySettings::timezone())->toBe('Europe/Paris')
        ->and(DisplaySettings::dateFormat())->toBe('d/m/Y');

    DB::table('settings')->insert([
        ['key' => 'timezone', 'value' => 'Mars/Olympus'],
        ['key' => 'date_format', 'value' => 'nope'],
    ]);
    DisplaySettings::forget();

    expect(DisplaySettings::timezone())->toBe('Europe/Paris')
        ->and(DisplaySettings::dateFormat())->toBe('d/m/Y');
});

it('keeps UTC in the read API', function () {
    DisplaySettings::save('America/New_York', 'Y-m-d');
    [$server] = enrolledServer();
    $server->update(['last_seen_at' => '2026-11-01 08:30:00']);
    $token = ApiClient::create(['name' => 'site', 'allowed_ips' => ['203.0.113.10']])->issueToken();

    $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->withToken($token)
        ->getJson("/api/v1/servers/{$server->id}")
        ->assertOk();

    expect($response->json('data.last_seen_at'))->toStartWith('2026-11-01T08:30:00');
});

it('leaves the API clients list to admins even with a stale page', function () {
    adminUser();
    $client = ApiClient::create(['name' => 'site', 'allowed_ips' => ['203.0.113.10']]);
    $user = adminUser();
    $this->actingAs($user, 'web');
    $page = Livewire::test(ListApiClients::class);

    $user->update(['role' => User::ROLE_USER]);

    $page->assertTableActionHidden('issueToken', $client);
});
