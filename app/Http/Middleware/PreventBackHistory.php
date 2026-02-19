<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PreventBackHistory
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     */
    public function handle($request, Closure $next): mixed
    {
        // Skip middleware if this is a valid-signed route request
        if ($request->hasValidSignature()) {
            return $next($request);
        }
        $response = $next($request);

        // BinaryFileResponse (file downloads) doesn't support the fluent ->header() API
        if ($response instanceof BinaryFileResponse) {
            $response->headers->set('Cache-Control', 'nocache, no-store, max-age=0, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', 'Sun, 02 Jan 1990 00:00:00 GMT');

            return $response;
        }

        return $response->header('Cache-Control', 'nocache, no-store, max-age=0, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', 'Sun, 02 Jan 1990 00:00:00 GMT');

    }
}
