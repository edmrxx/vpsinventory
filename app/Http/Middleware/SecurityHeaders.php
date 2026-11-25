<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers that tell the browser to be careful.
 *
 * There is no Content-Security-Policy here on purpose: the app prints an
 * inline <style> for the theme and an inline <script> for the sidebar, so a
 * strict policy needs a nonce on both. That is a change worth making with a
 * real deployment in front of you, not a guess.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Never let another site put this app in a frame.
        $response->headers->set('X-Frame-Options', 'DENY');

        // Trust the declared content type; do not sniff.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Send the full URL to our own pages, only the host to others.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Ask the browser not to hand these devices to the page.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=()'
        );

        // Keep signed-in pages out of shared caches.
        if ($request->user()) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        }

        return $response;
    }
}
