<?php
namespace App\Http\Middleware;
use Closure;
class SecurityHeaders
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set("X-Content-Type-Options", "nosniff");
        $response->headers->set("X-Frame-Options", "SAMEORIGIN");
        $response->headers->set("Referrer-Policy", "no-referrer");
        $response->headers->set("Cache-Control", "no-store, private");
        if ($request->isSecure()) {
            $response->headers->set(
                "Strict-Transport-Security",
                "max-age=31536000",
            );
        }
        return $response;
    }
}
