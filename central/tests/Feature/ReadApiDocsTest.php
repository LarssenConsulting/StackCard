<?php

use App\Http\Controllers\Api\ServerReadController;
use App\Models\ServerSection;
use Illuminate\Support\Facades\Route;

/**
 * docs/api/ is handed to the teams consuming the API: it must not drift from the routes.
 * Fields are not compared here: the responses list them one by one in ServerReadController.
 */
function readApiUris(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with((string) $route->getActionName(), ServerReadController::class))
        ->map(fn ($route) => '/'.$route->uri())
        ->sort()->values()->all();
}

it('documents every read route and every section in the OpenAPI file', function () {
    $spec = file_get_contents(base_path('../docs/api/stackcard-read-api.openapi.yaml'));

    preg_match_all('#^  (/api/\S+):$#m', $spec, $paths);
    $documented = $paths[1];
    sort($documented);

    expect($documented)->toBe(readApiUris());

    preg_match('/SectionName:\n\s+type: string\n\s+enum:\n((?:\s+- \w+\n)+)/', $spec, $enum);
    preg_match_all('/- (\w+)/', $enum[1], $sections);
    expect($sections[1])->toBe(ServerSection::NAMES);

    foreach (ServerSection::NAMES as $section) {
        expect($spec)->toContain("| `{$section}`");
    }
});

it('offers a request for every section in the Postman collection, without a token', function () {
    $collection = json_decode(file_get_contents(base_path('../docs/api/stackcard-read-api.postman_collection.json')), true);
    $folders = collect($collection['item'])->keyBy('name');

    expect(collect($folders['Sections']['item'])->pluck('name')->all())->toBe(ServerSection::NAMES)
        ->and(collect($collection['variable'])->firstWhere('key', 'token')['value'])->toBe('')
        ->and($collection['auth']['bearer'][0]['value'])->toBe('{{token}}');
});
