<?php

use App\Filament\Resources\ApiClients\Pages\CreateApiClient;
use App\Filament\Resources\ApiClients\Pages\EditApiClient;
use App\Filament\Resources\ApiClients\Pages\ListApiClients;
use App\Models\ApiClient;
use App\Models\User;
use App\Rules\AllowedIpEntry;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(), 'web');
});

it('creates a client with its allowed addresses', function () {
    Livewire::test(CreateApiClient::class)
        ->fillForm(['name' => 'site-cve', 'allowed_ips' => ['203.0.113.10', '2001:db8::/64']])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ApiClient::sole()->allowed_ips)->toBe(['203.0.113.10', '2001:db8::/64']);
});

it('refuses a client without an address', function () {
    Livewire::test(CreateApiClient::class)
        ->fillForm(['name' => 'site-cve', 'allowed_ips' => []])
        ->call('create')
        ->assertHasFormErrors(['allowed_ips']);
});

it('refuses an address that would open the API too wide', function (string $entry) {
    Livewire::test(CreateApiClient::class)
        ->fillForm(['name' => 'site-cve', 'allowed_ips' => [$entry]])
        ->call('create')
        ->assertHasFormErrors();

    expect(ApiClient::count())->toBe(0);
})->with(['0.0.0.0/0', '::/0', '10.0.0.0/8', '203.0.113.0/23', '2001:db8::/32', 'not-an-ip', '203.0.113.10/33', '203.0.113.10/x']);

it('validates an entry', function (string $entry, bool $valid) {
    expect(AllowedIpEntry::isValid($entry))->toBe($valid);
})->with([
    ['203.0.113.10', true],
    ['203.0.113.0/24', true],
    ['203.0.113.10/32', true],
    ['2001:db8::1', true],
    ['2001:db8::/48', true],
    ['203.0.113.0/23', false],
    ['2001:db8::/47', false],
    ['203.0.113.300', false],
    ['203.0.113.10/-1', false],
    ['example.com', false],
]);

it('shows the token once, and replaces the previous one', function () {
    $client = ApiClient::create(['name' => 'site-cve', 'allowed_ips' => ['203.0.113.10']]);
    $old = $client->issueToken();

    $page = Livewire::test(ListApiClients::class)
        ->assertSee('site-cve')
        ->assertSee('203.0.113.10')
        ->callTableAction('issueToken', $client);

    $token = $page->get('mountedActions.0.arguments.token');
    $page->assertMountedActionModalSee('Jeton créé')->assertMountedActionModalSeeHtml([$token]);

    expect($client->currentToken()->token)->toBe(hash('sha256', explode('|', $token)[1]))
        ->and($client->tokens()->count())->toBe(1)
        ->and($client->currentToken()->token)->not->toBe(hash('sha256', explode('|', $old)[1]))
        ->and($client->currentToken()->can(ApiClient::READ_ABILITY))->toBeTrue()
        ->and($client->currentToken()->can('inventory:write'))->toBeFalse()
        ->and($client->currentToken()->expires_at->isFuture())->toBeTrue();
});

it('edits the addresses of a client', function () {
    $client = ApiClient::create(['name' => 'site-cve', 'allowed_ips' => ['203.0.113.10']]);

    Livewire::test(EditApiClient::class, ['record' => $client->getRouteKey()])
        ->fillForm(['allowed_ips' => ['203.0.113.11']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($client->refresh()->allowed_ips)->toBe(['203.0.113.11']);
});

it('lists a client without a token', function () {
    ApiClient::create(['name' => 'site-cve', 'allowed_ips' => ['203.0.113.10']]);

    Livewire::test(ListApiClients::class)->assertSee('Aucun jeton')->assertSee('Jamais');
});
