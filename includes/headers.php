<?php
/**
 * HTTP Security Headers — require_once this file early in every page.
 * Centralises all security-related response headers in one place.
 */

if (!headers_sent()) {
    // Prevent MIME-type sniffing
    header('X-Content-Type-Options: nosniff');

    // Block framing (clickjacking)
    header('X-Frame-Options: DENY');

    // Control referrer leakage
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Disable unnecessary browser features
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    // HSTS — only send over HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    // Content Security Policy — allows Chart.js and Font Awesome CDN
    header("Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; "
        . "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'none';"
    );
}
