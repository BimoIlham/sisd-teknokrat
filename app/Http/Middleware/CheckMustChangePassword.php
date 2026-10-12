<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMustChangePassword
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check() && auth()->user()->must_change_password) {
            if (! $request->routeIs('password.change') &&
                ! $request->routeIs('password.update') &&
                ! $request->routeIs('logout')) {
                return redirect()->route('password.change')
                    ->with('warning', 'Demi keamanan akun Anda, silakan ubah kata sandi terlebih dahulu sebelum melanjutkan.');
            }
        }

        return $next($request);
    }
}
