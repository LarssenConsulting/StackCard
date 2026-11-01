<?php

use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Resources\Servers\RelationManagers\FirewallRulesRelationManager;
use App\Livewire\Firewall\Fail2banTable;
use App\Livewire\Firewall\IptablesLegacyTable;
use App\Livewire\Firewall\IptablesTable;
use App\Livewire\Firewall\NftablesTable;
use App\Models\Server;
use App\Models\User;
use App\Support\IptablesRules;
use App\Support\NftRuleset;
use Livewire\Livewire;

beforeEach(function () {
    [$this->server] = enrolledServer();
    $this->actingAs(User::factory()->create(), 'web');
});

function networkSection(Server $server, array $data, array $sourceErrors = []): void
{
    $server->sections()->create([
        'section' => 'network', 'collected_at' => now(), 'sha256' => str_repeat('a', 64), 'error' => null,
        'source_errors' => $sourceErrors, 'data' => $data, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
}

function firewallTable(string $class, Server $server)
{
    return Livewire::test($class, ['serverId' => $server->getKey()]);
}

function nftRuleset(): array
{
    return ['nftables' => [
        ['metainfo' => ['version' => '1.0.9']],
        ['table' => ['family' => 'inet', 'name' => 'filter', 'handle' => 1]],
        ['chain' => ['family' => 'inet', 'table' => 'filter', 'name' => 'input', 'type' => 'filter', 'hook' => 'input', 'prio' => 0, 'policy' => 'drop']],
        ['chain' => ['family' => 'inet', 'table' => 'filter', 'name' => 'empty']],
        ['set' => ['family' => 'inet', 'table' => 'filter', 'name' => 'blocked', 'type' => 'ipv4_addr', 'elem' => ['10.0.0.1', ['prefix' => ['addr' => '10.1.0.0', 'len' => 16]]]]],
        ['rule' => ['family' => 'inet', 'table' => 'filter', 'chain' => 'input', 'expr' => [
            ['match' => ['op' => '==', 'left' => ['payload' => ['protocol' => 'tcp', 'field' => 'dport']], 'right' => 22]],
            ['counter' => []],
            ['accept' => null],
        ], 'comment' => 'ssh']],
        ['rule' => ['family' => 'inet', 'table' => 'filter', 'chain' => 'input', 'expr' => [
            ['match' => ['op' => 'in', 'left' => ['ct' => ['key' => 'state']], 'right' => ['established', 'related']]],
            ['jump' => ['target' => 'allowed']],
        ]]],
        ['rule' => ['family' => 'inet', 'table' => 'filter', 'chain' => 'input', 'expr' => [
            ['match' => ['op' => '!=', 'left' => ['meta' => ['key' => 'iifname']], 'right' => 'lo']],
            ['weird_statement' => ['a' => 1]],
        ]]],
        ['flowtable' => ['name' => 'f']],
    ]];
}

it('turns an nft ruleset into rows grouped by table and chain', function () {
    $table = 'inet filter';

    expect(NftRuleset::rows(nftRuleset()))->toBe([
        ['table' => $table, 'chain' => 'set blocked', 'conditions' => 'type ipv4_addr : { 10.0.0.1, 10.1.0.0/16 }', 'action' => null, 'comment' => null],
        ['table' => $table, 'chain' => 'input', 'conditions' => 'Politique par défaut (type filter, hook input, priority 0)', 'action' => 'drop', 'comment' => null],
        ['table' => $table, 'chain' => 'input', 'conditions' => 'tcp dport 22', 'action' => 'accept', 'comment' => 'ssh'],
        ['table' => $table, 'chain' => 'input', 'conditions' => 'ct state { established, related }', 'action' => 'jump allowed', 'comment' => null],
        ['table' => $table, 'chain' => 'input', 'conditions' => 'meta iifname != lo weird_statement {"a":1}', 'action' => null, 'comment' => null],
        ['table' => $table, 'chain' => 'empty', 'conditions' => 'Chaîne sans règle', 'action' => null, 'comment' => null],
        ['table' => null, 'chain' => null, 'conditions' => '{"flowtable":{"name":"f"}}', 'action' => null, 'comment' => null],
    ])->and(NftRuleset::ruleCount(nftRuleset()))->toBe(3);
});

it('turns iptables-save lines into rows, numbered per chain', function () {
    $rows = IptablesRules::rows([
        '*filter',
        ':INPUT DROP',
        ':DOCKER -',
        '-A INPUT -p tcp --dport 22 -m comment --comment "admin ssh" -j ACCEPT',
        '-A INPUT -s 10.0.0.0/8 -j REJECT --reject-with icmp-port-unreachable',
        '-A DOCKER -p tcp -j RETURN',
        'COMMIT',
        '*nat',
        '-A INPUT -g other',
        'something odd',
        5,
    ], 'IPv4');

    $row = fn ($table, $chain, $position, $conditions, $action, $comment = null) => [
        'family' => 'IPv4', 'table' => $table, 'chain' => $chain, 'position' => $position,
        'conditions' => $conditions, 'action' => $action, 'comment' => $comment,
    ];
    expect($rows)->toBe([
        $row('filter', 'INPUT', null, 'Politique par défaut', 'DROP'),
        $row('filter', 'INPUT', 1, '-p tcp --dport 22', 'ACCEPT', 'admin ssh'),
        $row('filter', 'INPUT', 2, '-s 10.0.0.0/8', 'REJECT --reject-with icmp-port-unreachable'),
        $row('filter', 'DOCKER', 1, '-p tcp', 'RETURN'),
        $row('nat', 'INPUT', 1, null, 'other'),
        $row('nat', null, null, 'something odd', null),
    ]);
});

it('puts one table per firewall under the same tab', function () {
    Livewire::test(FirewallRulesRelationManager::class, ['ownerRecord' => $this->server, 'pageClass' => ViewServer::class])
        ->assertSee('UFW')
        ->assertSeeLivewire(NftablesTable::class)
        ->assertSeeLivewire(IptablesTable::class)
        ->assertSeeLivewire(IptablesLegacyTable::class)
        ->assertSeeLivewire(Fail2banTable::class)
        ->assertSeeHtml('Le pare-feu de l’hébergeur n’est pas visible depuis le serveur');
});

it('lists the rules of each firewall in its own table', function () {
    networkSection($this->server, [
        'nft' => nftRuleset(),
        'iptables' => ['v4' => ['*filter', ':INPUT DROP', '-A INPUT -p tcp --dport 443 -j ACCEPT', 'COMMIT'], 'v6' => []],
        'iptables_legacy' => ['v4' => ['*filter', '-A INPUT -p udp -j DROP'], 'v6' => []],
    ]);

    firewallTable(NftablesTable::class, $this->server)
        ->assertSee('nftables')
        ->assertSee('3 règles')
        ->assertSee('tcp dport 22')
        ->assertDontSee('--dport 443');

    firewallTable(IptablesTable::class, $this->server)
        ->assertSee('IPv4 : 1 règle · IPv6 : Aucune règle')
        ->assertSee('-p tcp --dport 443')
        ->assertSee('ACCEPT')
        ->assertDontSee('-p udp');

    firewallTable(IptablesLegacyTable::class, $this->server)
        ->assertSee('iptables-legacy')
        ->assertSee('-p udp')
        ->assertDontSee('--dport 443');
});

it('filters a table with its search box', function () {
    networkSection($this->server, ['iptables' => ['v4' => ['*filter', '-A INPUT -p tcp --dport 22 -j ACCEPT', '-A INPUT -p tcp --dport 443 -j ACCEPT'], 'v6' => []]]);

    firewallTable(IptablesTable::class, $this->server)
        ->set('tableSearch', '443')
        ->assertSee('--dport 443')
        ->assertDontSee('--dport 22');
});

it('tells a tool that is absent from one that failed', function () {
    networkSection($this->server, [
        'nft' => null,
        'iptables' => ['v4' => null, 'v6' => null],
        'iptables_legacy' => ['v4' => null, 'v6' => []],
    ], ['nft' => 'not_installed', 'iptables.v4' => 'exited 1', 'iptables.v6' => 'not_installed', 'iptables_legacy.v4' => 'not_installed']);

    firewallTable(NftablesTable::class, $this->server)->assertSee('Non installé');
    firewallTable(IptablesTable::class, $this->server)->assertSee('IPv4 : Non lu : exited 1 · IPv6 : Non installé');
    firewallTable(IptablesLegacyTable::class, $this->server)->assertSee('IPv4 : Non installé · IPv6 : Aucune règle');
});

it('says when the agent is too old for the detail', function () {
    firewallTable(NftablesTable::class, $this->server)->assertSee('il faut un agent 0.5.2 ou plus récent');

    networkSection($this->server, ['nft' => null, 'ufw' => null], ['nft' => 'not_installed']);
    firewallTable(NftablesTable::class, $this->server)->assertSee('Non installé');
    firewallTable(IptablesTable::class, $this->server)->assertSee('il faut un agent 0.5.2 ou plus récent');
});

it('says when the section could not be read', function () {
    $this->server->sections()->create([
        'section' => 'network', 'error' => 'timeout', 'source_errors' => [], 'data' => null,
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    firewallTable(IptablesTable::class, $this->server)->assertSee('Non lu lors du dernier envoi : timeout');
});

it('escapes what an agent sends', function () {
    networkSection($this->server, [
        'nft' => ['nftables' => [['rule' => ['family' => 'inet', 'table' => '<script>x</script>', 'chain' => 'c', 'expr' => [], 'comment' => '<img src=x>']]]],
        'iptables' => ['v4' => ['-A INPUT -m comment --comment "<script>y</script>"', '-A INPUT <b>z</b>'], 'v6' => []],
    ]);

    firewallTable(NftablesTable::class, $this->server)
        ->assertDontSeeHtml('<script>x</script>')
        ->assertDontSeeHtml('<img src=x>');
    firewallTable(IptablesTable::class, $this->server)
        ->assertDontSeeHtml('<script>y</script>')
        ->assertDontSeeHtml('<b>z</b>');
});

it('survives malformed data', function () {
    networkSection($this->server, [
        'nft' => ['nftables' => ['text', 5, ['rule' => 'x'], ['rule' => ['chain' => ['a'], 'family' => ['b'], 'expr' => ['y', 3, []]]], ['chain' => ['name' => ['n']]]]],
        'iptables' => ['v4' => ['ok', 5], 'v6' => 'nope'],
        'iptables_legacy' => [],
    ]);
    firewallTable(NftablesTable::class, $this->server)->assertSuccessful()->assertSee('2 règles');
    firewallTable(IptablesTable::class, $this->server)->assertSuccessful();
    firewallTable(IptablesLegacyTable::class, $this->server)->assertSuccessful();

    networkSection($this->server, ['nft' => ['nftables' => 'nope'], 'iptables' => 'nope', 'iptables_legacy' => null]);
    firewallTable(NftablesTable::class, $this->server)->assertSuccessful()->assertSee('Aucune règle');
    firewallTable(IptablesTable::class, $this->server)->assertSuccessful();
    firewallTable(IptablesLegacyTable::class, $this->server)->assertSuccessful();
});

it('lists the Fail2Ban jails with their configuration', function () {
    networkSection($this->server, ['fail2ban' => [
        'enabled' => 'enabled', 'active' => 'active', 'config_errors' => ['Jail broken skipped'],
        'jails' => [
            ['name' => 'sshd', 'backend' => 'systemd', 'bantime' => '3600', 'findtime' => '600', 'maxretry' => '5',
                'ignoreip' => ['127.0.0.1/8', '::1'], 'logpaths' => ['/var/log/auth.log'],
                'actions' => [['name' => 'iptables-multiport', 'port' => 'ssh', 'protocol' => 'tcp']]],
        ],
    ]]);

    firewallTable(Fail2banTable::class, $this->server)
        ->assertSee('Fail2Ban')
        ->assertSee('Service actif · lancé au démarrage · 1 jail activée · Erreur de configuration : Jail broken skipped')
        ->assertSee('sshd')
        ->assertSee('3600')
        ->assertSee('iptables-multiport (ssh, tcp)')
        ->assertSee('127.0.0.1/8, ::1')
        ->assertSee('/var/log/auth.log');
});

it('says when Fail2Ban is stopped, absent or unread', function () {
    networkSection($this->server, ['fail2ban' => ['enabled' => 'disabled', 'active' => 'inactive', 'jails' => [], 'config_errors' => []]]);
    firewallTable(Fail2banTable::class, $this->server)->assertSee('Service inactif · non lancé au démarrage · aucune jail activée');

    networkSection($this->server, ['fail2ban' => null], ['fail2ban' => 'not_installed']);
    firewallTable(Fail2banTable::class, $this->server)->assertSee('Non installé');

    networkSection($this->server, ['fail2ban' => null], ['fail2ban' => 'exited 255']);
    firewallTable(Fail2banTable::class, $this->server)->assertSee('Non lu : exited 255');

    networkSection($this->server, ['nft' => null]);
    firewallTable(Fail2banTable::class, $this->server)->assertSee('il faut un agent 0.5.2 ou plus récent');
});

it('escapes and survives what an agent sends for Fail2Ban', function () {
    networkSection($this->server, ['fail2ban' => [
        'active' => ['x'], 'enabled' => 5, 'config_errors' => 'nope',
        'jails' => ['text', ['name' => '<script>x</script>', 'actions' => ['y', ['name' => ['z']]], 'ignoreip' => 'one', 'logpaths' => [['deep']], 'bantime' => ['a']]],
    ]]);

    firewallTable(Fail2banTable::class, $this->server)
        ->assertSuccessful()
        ->assertDontSeeHtml('<script>x</script>');
});
