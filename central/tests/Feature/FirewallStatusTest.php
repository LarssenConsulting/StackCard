<?php

use App\Filament\Resources\Servers\Pages\ListServers;
use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Models\User;
use App\Support\FirewallStatus;
use Livewire\Livewire;

/** nft -j output with one base chain on the input hook. */
function nftInput(string $policy, array $rules = [], string $chain = 'input', string $family = 'inet', string $table = 'filter'): array
{
    $entities = [
        ['metainfo' => ['version' => '1.1.3']],
        ['table' => ['family' => $family, 'name' => $table]],
        ['chain' => ['family' => $family, 'table' => $table, 'name' => $chain, 'type' => 'filter', 'hook' => 'input', 'prio' => 0, 'policy' => $policy]],
    ];
    foreach ($rules as [$ruleChain, $expr]) {
        $entities[] = ['rule' => ['family' => $family, 'table' => $table, 'chain' => $ruleChain, 'expr' => $expr]];
    }

    return ['nftables' => $entities];
}

function iptablesLines(array $lines): array
{
    return ['v4' => ['*filter', ...$lines, 'COMMIT'], 'v6' => null];
}

it('sees nftables filtering through a drop policy on the input hook, with UFW inactive', function () {
    $network = ['nft' => nftInput('drop', [['input', [['match' => ['op' => '==', 'left' => ['meta' => ['key' => 'iif']], 'right' => 'lo']], ['accept' => null]]]])];

    expect(FirewallStatus::engines($network, 'inactive'))->toBe(['nftables'])
        ->and(FirewallStatus::badge(['nftables']))->toBe(['label' => 'nftables', 'color' => 'success']);
});

it('sees a drop rule in a chain jumped to from the input chain', function () {
    $network = ['nft' => nftInput('accept', [
        ['input', [['jump' => ['target' => 'guard']]]],
        ['guard', [['match' => ['op' => '==', 'left' => ['payload' => ['protocol' => 'tcp', 'field' => 'dport']], 'right' => 23]], ['reject' => null]]],
    ])];

    expect(FirewallStatus::engines($network, null))->toBe(['nftables']);
});

it('does not count an accept-only input chain, nor the tables written by iptables-nft', function () {
    expect(FirewallStatus::engines(['nft' => nftInput('accept')], 'inactive'))->toBe([])
        ->and(FirewallStatus::engines(['nft' => nftInput('drop', chain: 'INPUT', family: 'ip')], 'inactive'))->toBe([]);
});

it('sees iptables through the INPUT policy or a rule reached from INPUT, but not Docker chains', function () {
    expect(FirewallStatus::engines(['iptables' => iptablesLines([':INPUT DROP [0:0]'])], 'inactive'))->toBe(['iptables'])
        ->and(FirewallStatus::engines(['iptables_legacy' => iptablesLines([
            ':INPUT ACCEPT [0:0]', ':custom - [0:0]', '-A INPUT -j custom', '-A custom -p tcp --dport 23 -j REJECT',
        ])], null))->toBe(['iptables_legacy'])
        ->and(FirewallStatus::engines(['iptables' => iptablesLines([
            ':INPUT ACCEPT [0:0]', ':FORWARD DROP [0:0]', ':DOCKER - [0:0]', '-A FORWARD -j DOCKER', '-A DOCKER -j DROP',
        ])], 'inactive'))->toBe([]);
});

it('does not count the iptables rules of an active UFW twice', function () {
    expect(FirewallStatus::engines(['iptables' => iptablesLines([':INPUT DROP [0:0]'])], 'active'))->toBe(['ufw']);
});

it('lists Fail2Ban, which alone does not protect a server', function () {
    $engines = FirewallStatus::engines(['fail2ban' => ['active' => 'active', 'jails' => [['name' => 'sshd']]]], 'inactive');

    expect($engines)->toBe(['fail2ban'])
        ->and(FirewallStatus::protects($engines))->toBeFalse()
        ->and(FirewallStatus::badge($engines)['label'])->toBe('Fail2Ban seul');
});

it('tells unknown from none, and survives malformed data', function () {
    expect(FirewallStatus::engines(null, null))->toBeNull()
        ->and(FirewallStatus::engines(null, 'inactive'))->toBe([])
        ->and(FirewallStatus::badge(null)['label'])->toBe('Inconnu')
        ->and(FirewallStatus::badge([])['label'])->toBe('Aucun')
        ->and(FirewallStatus::engines([
            'nft' => ['nftables' => ['x', ['chain' => 'y'], ['rule' => ['chain' => 'input', 'expr' => 'z']]]],
            'iptables' => ['v4' => [1, null, '*filter', ':INPUT'], 'v6' => 'x'],
            'fail2ban' => 'oops',
        ], 'inactive'))->toBe([]);
});

it('stores the firewalls at each inventory and keeps them when the network section fails', function () {
    [$server, $token] = enrolledServer();
    $snapshot = snapshotFixture();
    $snapshot['ufw']['status'] = 'inactive';
    $snapshot['sections']['network']['data']['nft'] = nftInput('drop');

    $this->withToken($token)->postJson('/api/v1/inventory', $snapshot)->assertOk();
    expect($server->refresh()->firewall_engines)->toContain('nftables')->not->toContain('ufw');

    $snapshot['sections']['network'] = ['collected_at' => null, 'sha256' => null, 'error' => 'timeout', 'source_errors' => [], 'data' => null];
    $this->withToken($token)->postJson('/api/v1/inventory', $snapshot)->assertOk();
    expect($server->refresh()->firewall_engines)->toContain('nftables');
});

it('shows the firewall on the server sheet and in the server list, with a filter', function () {
    $this->actingAs(User::factory()->create(), 'web');
    [$protected] = enrolledServer('web02', 'm1');
    $protected->forceFill(['ufw_status' => 'inactive', 'firewall_engines' => ['nftables', 'fail2ban']])->save();
    [$open] = enrolledServer('open', 'm2');
    $open->forceFill(['firewall_engines' => []])->save();
    [$unknown] = enrolledServer('unknown', 'm3');
    $unknown->forceFill(['firewall_engines' => null])->save();

    Livewire::test(ViewServer::class, ['record' => $protected->getRouteKey()])
        ->assertSee('nftables + Fail2Ban')
        ->assertSee('UFW inactif');

    Livewire::test(ListServers::class)
        ->assertSee('nftables + Fail2Ban')
        ->assertSee('Aucun')
        ->assertSee('Inconnu')
        ->filterTable('no_firewall')
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$protected, $unknown]);
});
