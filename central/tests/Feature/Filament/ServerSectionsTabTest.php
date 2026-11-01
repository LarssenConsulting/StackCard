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

it('shows the section JSON indented and coloured, in a scrollable block, escaped', function () {
    $html = view('filament.server-section.data', [
        'error' => null,
        'data' => ['a' => ['b' => '</span><script>x</script>', 'url' => 'https://h/é', 'n' => -1.5, 'on' => true, 'none' => null, 'list' => [1]]],
        'sourceErrors' => ['nft' => 'not_installed'],
    ])->render();

    expect($html)
        ->toContain('overflow:auto')
        ->toContain('max-height:60vh')
        ->toContain("{\n    <span class=\"sc-json-key\">&quot;a&quot;</span>: {\n        <span class=\"sc-json-key\">&quot;b&quot;</span>:")
        ->toContain('<span class="sc-json-string">&quot;https://h/é&quot;</span>')
        ->toContain('<span class="sc-json-number">-1.5</span>')
        ->toContain('<span class="sc-json-literal">true</span>')
        ->toContain('<span class="sc-json-literal">null</span>')
        ->toContain('<span class="sc-json-key">&quot;nft&quot;</span>: <span class="sc-json-string">&quot;not_installed&quot;</span>')
        ->not->toContain('<script>x</script>')
        ->not->toContain('</span><script>');
});

it('does not read a number or keyword inside a string as a token', function () {
    expect((string) App\Support\JsonHighlighter::html(['true 12 null "q" \\ end']))
        ->toBe("[\n    <span class=\"sc-json-string\">&quot;true 12 null \\&quot;q\\&quot; \\\\ end&quot;</span>\n]");
});

it('falls back to escaped plain text for a very large section', function () {
    $html = (string) App\Support\JsonHighlighter::html(['big' => str_repeat('<x>', 200000)]);

    expect($html)->not->toContain('<span')->not->toContain('<x>')->toContain('&lt;x&gt;');
});
