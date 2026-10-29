<?php

use App\Filament\Resources\Databases\Pages\ListDatabases;
use App\Filament\Resources\Databases\Pages\ViewDatabase;
use App\Filament\Resources\Servers\Pages\CreateServer;
use App\Filament\Resources\Servers\Pages\EditServer;
use App\Filament\Resources\Servers\Pages\ListServers;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Resources\Servers\RelationManagers\DatabasesRelationManager;
use App\Filament\Resources\Servers\RelationManagers\DockerContainersRelationManager;
use App\Filament\Resources\Servers\RelationManagers\FirewallRulesRelationManager;
use App\Filament\Resources\Servers\RelationManagers\NodeAppsRelationManager;
use App\Filament\Resources\Servers\RelationManagers\SitesRelationManager;
use App\Filament\Resources\Servers\RelationManagers\SnapshotsRelationManager;
use App\Filament\Resources\Sites\Pages\ListSites;
use App\Filament\Resources\Sites\Pages\ViewSite;
use App\Filament\Widgets\ExpiringCertificates;
use App\Filament\Widgets\InventoryStats;
use App\Filament\Widgets\PartialSites;
use App\Filament\Widgets\RecentCollectorErrors;
use App\Filament\Widgets\StaleServers;
use App\Models\Database;
use App\Models\DockerContainer;
use App\Models\EnrollmentToken;
use App\Models\NodeApp;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDbRef;
use App\Models\User;
use App\Support\AgentRelease;
use Filament\Facades\Filament;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(now()->parse('2026-10-14 12:00:00'));
    [$this->web, $token] = enrolledServer();
    $this->withToken($token)->postJson('/api/v1/inventory', snapshotFixture())->assertOk();
    app('auth')->forgetGuards();
    $this->actingAs(User::factory()->create(), 'web');
});

it('sends guests to the login page', function () {
    app('auth')->forgetGuards();
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('requires a time-based second factor to be available', function () {
    expect(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders())->not->toBeEmpty();
});

it('lists servers with their address and site count', function () {
    Livewire::test(ListServers::class)
        ->assertCanSeeTableRecords([$this->web])
        ->assertSee('10.0.0.11');
});

it('shows the agent version with the newer published one', function () {
    config(['discover.agent_version_url' => 'https://releases.test/agent/version.txt']);
    Http::fake(['releases.test/*' => Http::response("0.2.0\n")]);

    Livewire::test(ListServers::class)->assertSee('0.1.0 (0.2.0 disponible)');
    Livewire::test(ViewServer::class, ['record' => $this->web->getRouteKey()])->assertSee('0.1.0 (0.2.0 disponible)');
    Http::assertSentCount(1);
});

it('shows only the agent version when it is up to date or the release is unreadable', function (string $body, int $status) {
    config(['discover.agent_version_url' => 'https://releases.test/agent/version.txt']);
    Http::fake(['releases.test/*' => Http::response($body, $status)]);

    Livewire::test(ListServers::class)->assertSee('0.1.0')->assertDontSee('disponible');
})->with([
    'up to date' => ['0.1.0', 200],
    'missing' => ['Not Found', 404],
    'not a version' => ['<html>', 200],
]);

it('does not look for a newer agent without a version url', function () {
    Http::fake();

    expect(AgentRelease::describe('0.1.0'))->toBe('0.1.0');
    Http::assertNothingSent();
});

it('creates a server from its name only', function () {
    Livewire::test(CreateServer::class)
        ->fillForm(['name' => 'web02', 'notes' => 'VM Proxmox'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Server::where('name', 'web02')->sole()->status)->toBe(Server::STATUS_PENDING);
});

it('shows a server sheet', function () {
    Livewire::test(ViewServer::class, ['record' => $this->web->getRouteKey()])
        ->assertSee('Ubuntu 24.04.1 LTS')
        ->assertSee('web01.example.lan')
        ->assertSee('10.11.8-MariaDB');
});

it('lists a server sites and snapshots in its tabs', function () {
    Livewire::test(SitesRelationManager::class, ['ownerRecord' => $this->web, 'pageClass' => ViewServer::class])
        ->assertCanSeeTableRecords($this->web->sites()->primaryPerName()->get());
    Livewire::test(SnapshotsRelationManager::class, ['ownerRecord' => $this->web, 'pageClass' => ViewServer::class])
        ->assertCanSeeTableRecords($this->web->snapshots);
    Livewire::test(DatabasesRelationManager::class, ['ownerRecord' => $this->web, 'pageClass' => ViewServer::class])
        ->assertCanSeeTableRecords($this->web->databases)
        ->assertTableColumnStateSet('linked_sites_count', 1, Database::where('name', 'shop_prod')->sole());
});

it('generates an enrollment token and shows the install command once', function () {
    config([
        'app.url' => 'https://sentinel.example.fr/',
        'discover.agent_install_url' => 'https://downloads.example.fr/agent/install.sh',
    ]);

    Livewire::test(ViewServer::class, ['record' => $this->web->getRouteKey()])
        ->callAction('generateEnrollmentToken');

    expect(EnrollmentToken::where('server_id', $this->web->id)->whereNull('used_at')->count())->toBe(1);
    $mailbox = app(NotificationMailbox::class);
    $mailbox->flush();
    $body = (string) collect($mailbox->pull())->last()['body'];
    expect($body)
        ->toContain('curl -fsSL https://downloads.example.fr/agent/install.sh | sudo sh')
        ->toContain('<code style="user-select:all">sentinel.example.fr</code>')
        ->toContain('sudo discover-agent enroll --url https://sentinel.example.fr --token ');
});

it('regenerates a lost enrollment token from the list, the edit page or the sheet', function () {
    $pending = Server::create(['name' => 'web02', 'status' => Server::STATUS_PENDING]);
    $issued = fn () => EnrollmentToken::where('server_id', $pending->id)->whereNull('used_at')->sole();

    Livewire::test(ListServers::class)->callTableAction('generateEnrollmentToken', $pending);
    $first = $issued()->token_hash;

    Livewire::test(EditServer::class, ['record' => $pending->getRouteKey()])
        ->assertActionHasLabel('generateEnrollmentToken', 'Régénérer le jeton d’enrôlement')
        ->callAction('generateEnrollmentToken');

    // Only the latest token works: the lost one is replaced, not kept alongside.
    expect($issued()->token_hash)->not->toBe($first);
    Livewire::test(ViewServer::class, ['record' => $pending->getRouteKey()])
        ->assertSee('En attente, expire le');
});

it('shows whether a server still waits for its enrollment', function () {
    $pending = Server::create(['name' => 'web02', 'status' => Server::STATUS_PENDING]);

    Livewire::test(ViewServer::class, ['record' => $pending->getRouteKey()])
        ->assertActionHasLabel('generateEnrollmentToken', 'Générer un jeton d’enrôlement')
        ->assertSee('Aucun jeton valide');

    $this->travel(EnrollmentToken::VALID_HOURS + 1)->hours();
    $pending->enrollmentTokens()->create(['token_hash' => 'x', 'expires_at' => now()->subHour()]);
    Livewire::test(ViewServer::class, ['record' => $pending->getRouteKey()])->assertSee('Aucun jeton valide');
});

it('revokes a server', function () {
    Livewire::test(ViewServer::class, ['record' => $this->web->getRouteKey()])
        ->callAction('revoke');

    $this->web->refresh();
    expect($this->web->status)->toBe(Server::STATUS_REVOKED)
        ->and($this->web->tokens()->count())->toBe(0);
});

it('finds a site by alias and filters by type', function () {
    $shop = Site::where('server_name', 'shop.example.com')->primaryPerName()->get();
    $api = Site::where('server_name', 'api.example.com')->get();

    Livewire::test(ListSites::class)
        ->searchTable('www.shop')
        ->assertCanSeeTableRecords($shop)
        ->assertCanNotSeeTableRecords($api);

    Livewire::test(ListSites::class)
        ->filterTable('type', 'node')
        ->assertCanSeeTableRecords($api)
        ->assertCanNotSeeTableRecords($shop);
});

it('lists a site served on ports 80 and 443 once, as its HTTPS vhost', function () {
    $https = Site::where('server_name', 'shop.example.com')->where('port', 443)->sole();
    $http = Site::where('server_name', 'shop.example.com')->where('port', 80)->sole();
    $https->update(['aliases' => ['www.shop.example.com', 'www.shop.example.com']]);

    Livewire::test(ListSites::class)
        ->assertCanSeeTableRecords([$https])
        ->assertCanNotSeeTableRecords([$http])
        ->assertTableColumnStateSet('ports', '80, 443', $https)
        ->assertDontSee('www.shop.example.com, www.shop.example.com');
    Livewire::test(ViewSite::class, ['record' => $https->getRouteKey()])
        ->assertSee('80, 443');
});

it('shows the technologies of each site and finds sites by technology', function () {
    $shop = Site::where('server_name', 'shop.example.com')->where('port', 443)->sole();
    $api = Site::where('server_name', 'api.example.com')->sole();

    Livewire::test(ListSites::class)
        ->assertTableColumnStateSet('technologies', ['Laravel 12.3.0', 'Filament 4.1.2'], $shop)
        ->searchTable('Express')
        ->assertCanSeeTableRecords([$api])
        ->assertCanNotSeeTableRecords([$shop]);
    Livewire::test(ViewSite::class, ['record' => $shop->getRouteKey()])
        ->assertSee('Laravel 12.3.0');
});

it('lists the Node apps of a server, with the sites that use them', function () {
    Livewire::test(NodeAppsRelationManager::class, ['ownerRecord' => $this->web, 'pageClass' => ViewServer::class])
        ->assertCanSeeTableRecords($this->web->nodeApps)
        ->assertTableColumnStateSet('sites', ['api.example.com'], NodeApp::where('name', 'api')->sole())
        ->assertSee('127.0.0.1:3000')
        ->assertSee('Local uniquement')
        ->assertSee('Réseau')
        ->assertSee('Aucun (pas de vhost devant)')
        ->assertSee('Node')
        ->assertSee('22.11.0');
});

it('shows on a Node site the app that serves it', function () {
    $api = Site::where('server_name', 'api.example.com')->sole();

    Livewire::test(ViewSite::class, ['record' => $api->getRouteKey()])
        ->assertSee('Application Node')
        ->assertSee('api 1.4.0')
        ->assertSee('Node 20.18.0')
        ->assertSee('port 3000')
        ->assertSee('relation=1', escape: false);
});

it('lists the Docker containers of a server, with the sites that use them', function () {
    Livewire::test(DockerContainersRelationManager::class, ['ownerRecord' => $this->web, 'pageClass' => ViewServer::class])
        ->assertCanSeeTableRecords($this->web->dockerContainers)
        ->assertTableColumnStateSet('sites', ['app.example.com'], DockerContainer::where('name', 'shop-web-1')->sole())
        ->assertSee('nginx:1.27')
        ->assertSee('127.0.0.1:8080 → 80/tcp')
        ->assertSee('[::]:5432 → 5432/tcp')
        ->assertSee('En marche')
        ->assertSee('Arrêté')
        ->assertSee('unless-stopped');
});

it('shows on a Docker site the container that serves it', function () {
    $app = Site::where('server_name', 'app.example.com')->sole();

    Livewire::test(ViewSite::class, ['record' => $app->getRouteKey()])
        ->assertSee('Conteneur Docker')
        ->assertSee('shop-web-1')
        ->assertSee('nginx:1.27')
        ->assertSee('port 8080')
        ->assertSee('relation=2', escape: false);
});

it('shows the Docker version of a server', function () {
    Livewire::test(ViewServer::class, ['record' => $this->web->getRouteKey()])->assertSee('27.3.1');
});

it('lists the UFW rules of a server and shows its default policies', function () {
    Livewire::test(FirewallRulesRelationManager::class, ['ownerRecord' => $this->web, 'pageClass' => ViewServer::class])
        ->assertCanSeeTableRecords($this->web->firewallRules)
        ->assertSee('80,443/tcp (Apache Full)')
        ->assertSee('LIMIT IN')
        ->assertSee('app01 vers MySQL')
        ->assertSee('IPv6');

    Livewire::test(ViewServer::class, ['record' => $this->web->getRouteKey()])
        ->assertSee('Pare-feu UFW')
        ->assertSee('Actif')
        ->assertSee('entrant deny, sortant allow, routé disabled');
});

it('says when UFW is inactive', function () {
    $this->web->firewallRules()->delete();
    $this->web->update(['ufw_status' => 'inactive']);

    Livewire::test(FirewallRulesRelationManager::class, ['ownerRecord' => $this->web, 'pageClass' => ViewServer::class])
        ->assertSee('UFW est inactif sur ce serveur');
});

it('shows the chain from a site to its database account', function () {
    $site = Site::where('server_name', 'shop.example.com')->where('port', 443)->sole();

    Livewire::test(ViewSite::class, ['record' => $site->getRouteKey()])
        ->assertSee('web01')
        ->assertSee('/var/www/shop/public')
        ->assertSee('shop_prod')
        ->assertSee('shop@localhost');
});

it('filters orphan databases', function () {
    Livewire::test(ListDatabases::class)
        ->filterTable('orphans')
        ->assertCanSeeTableRecords(Database::where('name', 'orphan_db')->get())
        ->assertCanNotSeeTableRecords(Database::where('name', 'shop_prod')->get());
});

it('shows which sites use a database', function () {
    $database = Database::where('name', 'shop_prod')->sole();

    Livewire::test(ViewDatabase::class, ['record' => $database->getRouteKey()])
        ->assertSee('shop.example.com')
        ->assertSee('/var/www/shop/.env');
});

it('counts and shows a site served on two ports once', function () {
    // The HTTP and HTTPS vhosts of shop.example.com read the same .env.
    $https = SiteDbRef::whereHas('site', fn ($query) => $query->where('server_name', 'shop.example.com'))->sole();
    $http = Site::where('server_name', 'shop.example.com')->where('port', 80)->sole();
    SiteDbRef::create(['site_id' => $http->id] + $https->only([
        'db_name', 'db_user', 'db_host', 'source', 'database_id', 'db_user_id', 'resolution',
    ]));
    $database = Database::where('name', 'shop_prod')->sole();

    expect($database->linkedSiteRefs())->toHaveCount(1)
        ->and($database->linkedSiteRefs()->first()->ports)->toBe('80, 443');
    Livewire::test(ListDatabases::class)
        ->assertTableColumnStateSet('linked_sites_count', 1, $database);
    Livewire::test(ViewDatabase::class, ['record' => $database->getRouteKey()])
        ->assertSee('80, 443');
});

it('summarises the inventory on the dashboard', function () {
    Livewire::test(InventoryStats::class)->assertSee('Serveurs')->assertSee('Sites');
});

it('flags servers that stopped reporting', function () {
    $silent = Server::create(['name' => 'silent', 'status' => Server::STATUS_ACTIVE, 'last_seen_at' => now()->subHours(5)]);

    Livewire::test(StaleServers::class)
        ->assertCanSeeTableRecords([$silent])
        ->assertCanNotSeeTableRecords([$this->web]);
});

it('flags certificates expiring within 30 days', function () {
    Livewire::test(ExpiringCertificates::class)
        ->assertCanSeeTableRecords(Site::where('server_name', 'api.example.com')->get())
        ->assertCanNotSeeTableRecords(Site::where('server_name', 'shop.example.com')->get());
});

it('lists partial sites and recent collector errors', function () {
    Site::where('server_name', 'api.example.com')->update(['partial' => true]);

    Livewire::test(PartialSites::class)
        ->assertCanSeeTableRecords(Site::where('partial', true)->get());
    Livewire::test(RecentCollectorErrors::class)
        ->assertCanSeeTableRecords($this->web->snapshots);
});
