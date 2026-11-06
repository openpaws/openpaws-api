<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/*')) {
            return $next($request);
        }

        $incoming = $request->header('X-Request-Id');
        $id = is_string($incoming) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $incoming)
            ? $incoming
            : (string) Str::uuid();

        $request->headers->set('X-Request-Id', $id);
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
