<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * The read API answers only an ApiClient calling from one of its addresses.
 *
 * `$request->ip()` is the address of the connection, or behind a proxy named in
 * DISCOVER_TRUSTED_PROXIES the one it forwards. Never trust `*`: anyone could then forge
 * X-Forwarded-For and walk through this check.
 */
class EnsureApiClientIsAllowed
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->user();
        if (! $client instanceof ApiClient) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        $context = ['client' => $client->name, 'ip' => $request->ip(), 'path' => $request->path()];
        if (! $client->allowsIp($request->ip())) {
            Log::warning('API de lecture : adresse refusée', $context);

            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        Log::info('API de lecture', $context);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
