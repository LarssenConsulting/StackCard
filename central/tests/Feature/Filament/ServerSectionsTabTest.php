<?php

use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Filament\Resources\Servers\RelationManagers\SectionsRelationManager;
use App\Models\ServerSection;
use App\Models\User;
use Livewire\Livewire;

function sectionsTab($server)
{
    return Livewire::test(SectionsRelationManager::class, ['ownerRecord' => $server, 'pageClass' => ViewServer::class]);
}

function addSection($server, string $section, array $attributes = []): ServerSection
{
    return ServerSection::create(array_merge([
        'server_id' => $server->id, 'section' => $section, 'collected_at' => now(), 'sha256' => str_repeat('a', 64),
        'data' => ['k' => 'v'], 'first_seen_at' => now(), 'last_seen_at' => now(),
    ], $attributes));
}

beforeEach(function () {
    [$this->server] = enrolledServer();
    $this->actingAs(User::factory()->create(), 'web');
});

it('shows one row per section with its latest state', function () {
    $old = addSection($this->server, 'kernel', ['data' => ['v' => 'old']]);
    $new = addSection($this->server, 'kernel', ['data' => ['v' => 'new']]);
    $php = addSection($this->server, 'php');
    $pkg = addSection($this->server, 'packages');

    sectionsTab($this->server)
        ->assertCanSeeTableRecords([$pkg, $new, $php], inOrder: true)
        ->assertCanNotSeeTableRecords([$old]);
});

it('labels states in french', function () {
    addSection($this->server, 'packages');
    addSection($this->server, 'apt_sources', ['error' => 'not_installed', 'data' => null]);
    addSection($this->server, 'kernel', ['error' => 'timeout', 'data' => null]);
    addSection($this->server, 'network', ['error' => 'too_large', 'data' => null]);
    addSection($this->server, 'php', ['error' => 'boom failed', 'data' => null]);
    addSection($this->server, 'apache', ['source_errors' => ['one source failed']]);

    sectionsTab($this->server)
        ->assertSee('OK')
        ->assertSee('Non installé')
        ->assertSee('Délai dépassé')
        ->assertSee('Trop volumineux')
        ->assertSee('Erreur')
        ->assertSee('boom failed')
        ->assertSee('Partiel');
});

it('lists sections never received', function () {
    addSection($this->server, 'packages');

    sectionsTab($this->server)
        ->assertSee('Jamais reçues : apt_sources, kernel')
        ->assertDontSee('Jamais reçues : packages');
});

it('shows an empty state when nothing was received', function () {
    sectionsTab($this->server)
        ->assertSee('Aucune section détaillée reçue')
        ->assertSee('Jamais reçues : packages');
});

it('shows data as escaped json in a modal', function () {
    $row = addSection($this->server, 'docker', [
        'data' => ['name' => '<script>x</script>', 'accent' => 'é'],
        'source_errors' => ['<b>bad</b>'],
    ]);

    sectionsTab($this->server)
        ->mountTableAction('view', $row)
        ->assertMountedActionModalSeeHtml(['&lt;script&gt;x&lt;/script&gt;', '&lt;b&gt;bad&lt;/b&gt;', 'é'])
        ->assertMountedActionModalDontSeeHtml(['<script>x</script>', '<b>bad</b>']);
});

it('shows the full error text escaped in the modal', function () {
    $message = str_repeat('x', 300).' <b>boom</b> fin du message';
    $row = addSection($this->server, 'php', ['error' => $message, 'data' => null]);

    sectionsTab($this->server)
        ->mountTableAction('view', $row)
        ->assertMountedActionModalSeeHtml([e($message)])
        ->assertMountedActionModalDontSeeHtml(['<b>boom</b>']);
});

it('leaves the state label to say a known sentinel', function () {
    $row = addSection($this->server, 'kernel', ['error' => 'timeout', 'data' => null]);

    sectionsTab($this->server)
        ->mountTableAction('view', $row)
        ->assertMountedActionModalDontSeeHtml(['>timeout<']);
});

it('is read only', function () {
    $row = addSection($this->server, 'php');

    sectionsTab($this->server)
        ->assertTableActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('edit', record: $row)
        ->assertTableActionDoesNotExist('delete', record: $row)
        ->assertTableActionExists('view', record: $row);
});

it('shows the section JSON indented, in a scrollable block, escaped', function () {
    $html = view('filament.server-section.data', [
        'error' => null,
        'data' => ['a' => ['b' => '<script>x</script>', 'url' => 'https://h/é']],
        'sourceErrors' => ['nft' => 'not_installed'],
    ])->render();

    expect($html)
        ->toContain('overflow:auto')
        ->toContain('max-height:60vh')
        ->toContain("{\n    &quot;a&quot;: {\n        &quot;b&quot;:")
        ->toContain('https://h/é')
        ->toContain('&quot;nft&quot;: &quot;not_installed&quot;')
        ->not->toContain('<script>x</script>');
});
