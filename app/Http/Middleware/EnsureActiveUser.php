<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_active === false) {
            return response()->json(['message' => 'This staff account no longer has access.'], 403);
        }

        return $next($request);
    }
}
