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

        // Versioned static content (a ?v=<cache buster> URL, see
        // AssetCacheBustingUrlGenerator) is declared immutable by its controller:
        // the URL changes whenever the file does, so the browser may keep it.
        // Forcing no-store here would make every page load refetch it.
        if ($response->headers->hasCacheControlDirective('immutable')) {
            return $response;
        }

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
