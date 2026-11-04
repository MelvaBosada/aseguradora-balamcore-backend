<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SoloSuperadmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->es_superadmin, 403, 'No autorizado');
        return $next($request);
    }
}