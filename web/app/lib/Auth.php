<?php
declare(strict_types=1);

/** パスワードログイン（セッション）と CSRF トークン。 */
final class Auth
{
    public const MAX_FAILED_PER_15MIN = 10;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name('tagfinder');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function isConfigured(): bool
    {
        return (string) Config::get('password_hash') !== '';
    }

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['auth']);
    }

    /** ログインを試みる。失敗が続くと一時的にロックする。 */
    public static function login(string $password): string
    {
        if (!self::isConfigured()) {
            return 'not_configured';
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (RateLimit::failedLogins($ip) >= self::MAX_FAILED_PER_15MIN) {
            return 'locked';
        }
        if (password_verify($password, (string) Config::get('password_hash'))) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
            return 'ok';
        }
        RateLimit::recordFailedLogin($ip);
        return 'invalid';
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals(self::csrfToken(), $token);
    }
}
