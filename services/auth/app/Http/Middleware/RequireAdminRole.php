<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the authenticated user holds the 'admin' role.
 *
 * Must be applied after auth:sanctum — this middleware assumes a user is
 * already resolved on the request. Returns 403 if the role check fails.
 */
class RequireAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAdmin()) {
            return response()->json([
                'message' => 'Forbidden. Admin role required.',
            ], 403);
        }

        return $next($request);
    }
}
