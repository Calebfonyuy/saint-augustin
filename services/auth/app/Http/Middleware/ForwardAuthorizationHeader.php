<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FrankenPHP (and some other PHP-embedded servers) run PHP in worker mode
 * where the HTTP server layer may not propagate the Authorization header into
 * $_SERVER['HTTP_AUTHORIZATION'] — the key that Symfony's HeaderBag (and by
 * extension Laravel's $request->bearerToken()) reads from.
 *
 * This middleware runs before any auth guard and backfills the header into
 * the Symfony request from getallheaders() when it is absent. It is a no-op
 * on servers (php artisan serve, nginx+fpm) that already populate the key.
 */
class ForwardAuthorizationHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->headers->has('Authorization') && function_exists('getallheaders')) {
            $headers = getallheaders();

            // getallheaders() returns a case-preserved array; check both common
            // casings since the HTTP spec says header names are case-insensitive.
            $value = $headers['Authorization'] ?? $headers['authorization'] ?? null;

            if ($value !== null) {
                $request->headers->set('Authorization', $value);
            }
        }

        return $next($request);
    }
}
