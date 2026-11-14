<?php

use App\Http\Controllers\WikiController;
use App\Models\User;

/** Every wiki URL with its page title. */
function wikiPages(): array
{
    return collect(WikiController::PAGES)
        ->mapWithKeys(fn (array $page, string $slug) => [$slug === '' ? '/wiki' : "/wiki/{$slug}" => $page])
        ->all();
}

it('serves every page without logging in', function () {
    foreach (wikiPages() as $url => $page) {
        $this->get($url)
            ->assertOk()
            ->assertSee('<title>'.e($page['title']).' · Wiki StackCard</title>', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('href="/admin"', false);
    }
});

it('gives each table of contents entry an anchor on its page', function () {
    foreach (wikiPages() as $url => $page) {
        $html = $this->get($url)->getContent();
        foreach (array_keys($page['toc']) as $anchor) {
            expect($html)->toContain("id=\"{$anchor}\"");
        }
    }
});

it('answers 404 for an unknown page', function () {
    $this->get('/wiki/inconnue')->assertNotFound();
    $this->get('/wiki/accueil')->assertNotFound();
});

it('is also available to a logged-in user', function () {
    $this->actingAs(User::factory()->create(), 'web');

    foreach (array_keys(wikiPages()) as $url) {
        $this->get($url)->assertOk();
    }
});

it('gives every diagram an accessible title and description', function () {
    foreach (array_keys(wikiPages()) as $url) {
        $html = $this->get($url)->getContent();
        preg_match_all('/<svg class="dg"[^>]*>/', $html, $svgs);
        foreach ($svgs[0] as $svg) {
            expect($svg)->toContain('role="img"')->toContain('aria-labelledby=');
        }
        expect(substr_count($html, '<svg class="dg"'))
            ->toBe(substr_count($html, '<desc id='));
    }
});

it('keeps the public pages anonymous', function () {
    // The real address of this central must never be rendered on a public page.
    config(['app.url' => 'https://central-reel.invalid']);
    $allowed = '/^(192\.0\.2|198\.51\.100|203\.0\.113)\.\d{1,3}$|^127\.0\.0\.1$|^10\.\d{1,3}\.\d{1,3}\.\d{1,3}$/';

    foreach (array_keys(wikiPages()) as $url) {
        $html = $this->get($url)->getContent();

        preg_match_all('/(?<![\d.])\d{1,3}(?:\.\d{1,3}){3}(?![\d.])/', $html, $ips);
        foreach ($ips[0] as $ip) {
            expect($ip)->toMatch($allowed, "{$url} : adresse IP {$ip}");
        }
        expect(stripos($html, 'larssen'))->toBeFalse("{$url} : nom propre");
        expect($html)->not->toContain('central-reel.invalid');
        // Links are relative, and the only host named is the documentation one.
        preg_match_all('#https?://([a-z0-9.-]+)#i', $html, $hosts);
        expect(array_unique($hosts[1]))->each->toBeIn(['central.example.com', 'www.w3.org']);
        expect(preg_match('/\d+\|[A-Za-z0-9]{20,}/', $html))->toBe(0, "{$url} : jeton");
    }
});
