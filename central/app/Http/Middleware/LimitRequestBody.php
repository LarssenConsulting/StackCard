<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitRequestBody
{
    /** The agent caps sections at 4 MiB each and 16 MiB together; the rest of the inventory is small. */
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        if (strlen($request->getContent()) > self::MAX_BYTES) {
            return response()->json(['message' => 'Inventaire trop volumineux (20 Mo maximum).'], 413);
        }

        return $next($request);
    }
}
