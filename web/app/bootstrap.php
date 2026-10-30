<?php
declare(strict_types=1);

// 共通の初期化。public/*.php から require される。
const APP_DIR = __DIR__;

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

$autoload = APP_DIR . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    $autoload = APP_DIR . '/vendor/autoload.php';
}
if (is_file($autoload)) {
    require_once $autoload;
}

foreach (['Config', 'Tags', 'Sources', 'Selector', 'Query', 'Auth', 'RateLimit', 'Analyzer'] as $name) {
    require_once APP_DIR . "/lib/{$name}.php";
}

/** JSON を返して終了する。 */
function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    header('X-Robots-Tag: noindex, nofollow');
}
