<?php

namespace Raccount\Sso\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectAuthRoutesToSso
{
    public function handle(Request $request, Closure $next): Response
    {
        $takenOver = (array) config('raccount-sso.exclusive.routes', []);

        if ($takenOver === [] || $request->user() !== null || $request->route() === null) {
            return $next($request);
        }

        if (in_array($request->route()->getName(), $takenOver, true)) {
            return redirect()->route('raccount.login');
        }

        return $next($request);
    }
}
