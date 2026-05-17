<?php

namespace Blax\Workkit\Middleware;

use Blax\Workkit\Services\ResponseService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $action = 'continue'): Response
    {
        if (! Auth::check()) {
            return ResponseService::apiError(
                "You need to be logged in to {$action}.",
                Response::HTTP_UNAUTHORIZED,
                type: 'AuthenticationException',
            );
        }

        return $next($request);
    }
}
