<?php
declare(strict_types=1);

/** ファイルベースの簡易カウンタ（DB 不要）。app/var 配下に保存する。 */
final class RateLimit
{
    private static function file(string $name): string
    {
        return Config::varDir() . '/' . $name . '.json';
    }

    /**
     * 現在の窓内のタイムスタンプ一覧を更新して返す。$record が true なら今回分を追加する。
     *
     * @return list<int>
     */
    private static function touch(string $name, int $windowSec, bool $record): array
    {
        $path = self::file($name);
        $fp = fopen($path, 'c+');
        if ($fp === false) {
            return [];
        }
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $stamps = $raw ? (json_decode($raw, true) ?: []) : [];
        $now = time();
        $stamps = array_values(array_filter($stamps, fn($t) => is_int($t) && $t > $now - $windowSec));
        if ($record) {
            $stamps[] = $now;
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($stamps));
        flock($fp, LOCK_UN);
        fclose($fp);
        return $stamps;
    }

    /** 解析の実行枠を1つ消費する。上限に達していれば false。 */
    public static function consumeAnalysis(): bool
    {
        $limit = (int) Config::get('rate_limit_per_hour');
        $current = self::touch('analysis', 3600, false);
        if (count($current) >= $limit) {
            return false;
        }
        self::touch('analysis', 3600, true);
        return true;
    }

    public static function failedLogins(string $ip): int
    {
        return count(self::touch('login_' . md5($ip), 900, false));
    }

    public static function recordFailedLogin(string $ip): void
    {
        self::touch('login_' . md5($ip), 900, true);
    }
}
