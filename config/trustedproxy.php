<?php

/*
 * Proxies whose X-Forwarded-For, -Proto and -Port headers Laravel believes.
 *
 * TLS ends at the host's nginx, which proxies to the web container on
 * 127.0.0.1. By the time a request reaches PHP-FPM, its REMOTE_ADDR is
 * Docker's bridge gateway, a private address. Trust private ranges by
 * default and nothing public, so a client can't fake its own IP or scheme.
 *
 * Set TRUSTED_PROXIES to a comma-separated list of IPs or CIDR ranges to
 * narrow it. Never set it to "*": then Laravel takes the leftmost
 * X-Forwarded-For entry, which the client controls.
 */

$proxies = env('TRUSTED_PROXIES', '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16');

return [

    'proxies' => array_values(array_filter(array_map('trim', explode(',', (string) $proxies)))),

];
