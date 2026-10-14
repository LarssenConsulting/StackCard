<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitRequestBody
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        if (strlen($request->getContent()) > self::MAX_BYTES) {
            return response()->json(['message' => 'Inventaire trop volumineux (5 Mo maximum).'], 413);
        }

        return $next($request);
    }
}
