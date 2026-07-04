<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Permissions Policy (modern standard)
        // We ensure microphone is allowed for (self) to enable live recording features
        $response->headers->set('Permissions-Policy', 'midi=(), sync-xhr=(), microphone=(self), camera=(), magnetometer=(), gyroscope=(), fullscreen=(self), payment=()', false);

        return $response;
    }
}
