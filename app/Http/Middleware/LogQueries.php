<?php

namespace App\Http\Middleware;

use App\Services\LogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class LogQueries
{
    public function handle(Request $request, Closure $next): Response
    {
        DB::listen(function ($query) {
            LogService::query(
                $query->sql,
                $query->bindings,
                $query->time
            );
        });

        return $next($request);
    }
}
