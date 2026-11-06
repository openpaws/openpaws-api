<?php

/*
 * Proxies whose X-Forwarded-* headers are trusted (Ruling U10). Read by Laravel's global
 * TrustProxies middleware, so `$request->ip()` — and every per-IP rate limit — sees the
 * browser's address on the UI's server-side (SSR) calls, which forward it in X-Forwarded-For.
 *
 * TRUSTED_PROXIES is a comma-separated list of IPs/CIDRs (default 127.0.0.1). In production
 * list ONLY the UI server(s) (and a load balancer in front of the API, if any): any client
 * connecting from a trusted address can set its own IP through X-Forwarded-For.
 *
 * Kept in config (not env() in bootstrap/app.php) so it still applies after `config:cache`.
 */
return [
    'proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1')),
    ), fn (string $ip) => $ip !== '')),
];
