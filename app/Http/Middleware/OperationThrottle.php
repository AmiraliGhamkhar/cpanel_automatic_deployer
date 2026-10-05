<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Support\Facades\RateLimiter;
class OperationThrottle
{
    public function handle($request, Closure $next)
    {
        if ($request->isMethod("POST")) {
            $key =
                "panel-operations:" . ($request->user()?->id ?? $request->ip());
            if (RateLimiter::tooManyAttempts($key, 90)) {
                abort(429, "Too many panel requests. Try again in one minute.");
            }
            RateLimiter::hit($key, 60);
        }
        return $next($request);
    }
}
