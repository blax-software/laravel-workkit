<?php

namespace Blax\Workkit\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Laravel\Sanctum\PersonalAccessToken;

class AuthenticateByBearerTokenMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $bearer = request()->header('Authorization', $request->get('token'));

            cache()->remember('bearer_' . $bearer, 1800, function () use ($bearer) {
                $bearer = explode(' ', $bearer);
                $bearer = end($bearer);

                $tokenable = optional(PersonalAccessToken::findToken(@$bearer))->tokenable;

                if ($tokenable) {
                    Auth::login($tokenable);
                }
            });
        } catch (\Exception $e) {
        }

        return $next($request);
    }
}
