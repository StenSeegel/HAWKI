<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ExtensionAccess
{
    /**
     * Handle an incoming request.
     *
     * Usage in extension routes:
     *   ->middleware(['web', 'auth', 'extension_access:extension.demo.access'])
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = Auth::user();

        if (! $user) {
            return redirect('/login');
        }

        if (! $user->hasAccess($permission)) {
            abort(403, 'Access denied. You do not have the required permission: '.$permission);
        }

        return $next($request);
    }
}
