<?php
declare(strict_types=1);

final class Config
{
    private static ?array $values = null;

    private const DEFAULTS = [
        'anthropic_api_key' => '',
        'model' => 'claude-opus-5-5',
        'effort' => 'medium',
        'use_fallback' => true,
        'password_hash' => '',
        'allow_mock' => false,
        'min_count' => 300,
        'rate_limit_per_hour' => 30,
        'max_pdf_mb' => 10,
    ];

    public static function load(): array
    {
        if (self::$values !== null) {
            return self::$values;
        }
        $values = self::DEFAULTS;
        $file = APP_DIR . '/config.php';
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                $values = array_merge($values, $loaded);
            }
        }
        if ($values['anthropic_api_key'] === '') {
            $env = getenv('ANTHROPIC_API_KEY');
            $values['anthropic_api_key'] = $env === false ? '' : $env;
        }
        return self::$values = $values;
    }

    /** @return mixed */
    public static function get(string $key)
    {
        return self::load()[$key] ?? null;
    }

    /** テスト用: 設定を差し替える。 */
    public static function override(array $values): void
    {
        self::$values = array_merge(self::DEFAULTS, $values);
    }

    public static function dataDir(): string
    {
        $dir = APP_DIR . '/data';
        return is_dir($dir) ? $dir : dirname(APP_DIR, 2) . '/data';
    }

    public static function sharedDir(): string
    {
        $dir = APP_DIR . '/shared';
        return is_dir($dir) ? $dir : dirname(APP_DIR, 2) . '/shared';
    }

    public static function exampleDir(): string
    {
        $dir = APP_DIR . '/examples';
        return is_dir($dir) ? $dir : dirname(APP_DIR, 2) . '/examples';
    }

    public static function varDir(): string
    {
        return APP_DIR . '/var';
    }
}
