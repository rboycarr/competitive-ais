<?php

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Laravel\Horizon\Horizon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response from the application (P1-08, docs/07 Headers).
 *
 * CSP with a per-request nonce: only scripts and styles from this origin or carrying the nonce run. Vite's tags
 * (@vite) and Horizon's inline assets get the nonce automatically. In local development with the Vite dev server
 * running, its origin is allowed as well, and dev-only injected styles need 'unsafe-inline'.
 * HSTS only in production over HTTPS. Static files served by nginx directly are outside this middleware.
 */
class SecurityHeaders
{
    public function __construct(private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        if (class_exists(Horizon::class)) {
            Horizon::cspNonce($nonce);
        }

        $response = $next($request);

        $headers = [
            'Content-Security-Policy' => $this->policy($nonce),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            // Voice capture (Phase 5) will need microphone=(self).
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ];
        if ($this->app->isProduction() && $request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        $script = ["'self'", "'nonce-$nonce'"];
        $style = ["'self'", "'nonce-$nonce'"];
        $connect = ["'self'"];

        if (($dev = $this->viteDevServer()) !== null) {
            $script[] = $dev;
            $style = ["'self'", $dev, "'unsafe-inline'"]; // Vite injects <style> tags without a nonce in dev
            $connect[] = $dev;
            $connect[] = (string) preg_replace('#^http#', 'ws', $dev);
        }

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            'style-src '.implode(' ', $style),
            'connect-src '.implode(' ', $connect),
            "img-src 'self' data:",
            "font-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /** The Vite dev server origin, only outside production and while it runs (public/hot). */
    private function viteDevServer(): ?string
    {
        if ($this->app->isProduction() || ! is_file($hot = Vite::hotFile())) {
            return null;
        }
        $origin = trim((string) file_get_contents($hot));

        return preg_match('#^https?://[A-Za-z0-9.\-\[\]:]+$#', $origin) === 1 ? $origin : null;
    }
}
