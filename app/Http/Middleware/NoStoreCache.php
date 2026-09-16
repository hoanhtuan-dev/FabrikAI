<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NoStoreCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        // Prevent the browser from caching auth redirects (fixes ERR_TOO_MANY_REDIRECTS from stale cache).
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }
}
