<?php
declare(strict_types=1);

final class SourceException extends RuntimeException
{
}

/** 入力資料の取得。URL は SSRF 対策（公開IPのみ・ポート80/443のみ・リダイレクト再検証）を行う。 */
final class Sources
{
    public const MAX_TEXT_CHARS = 150000;
    public const MAX_FETCH_BYTES = 5 * 1024 * 1024;
    public const FETCH_TIMEOUT_SEC = 20;
    public const MAX_REDIRECTS = 3;
    public const MAX_URLS = 3;
    public const MAX_PDFS = 3;

    /** @return array{kind:string,label:string,data:string} */
    public static function pdf(string $bytes, string $label): array
    {
        if (strncmp($bytes, '%PDF-', 5) !== 0) {
            throw new SourceException("{$label}: PDF ではありません。");
        }
        $maxBytes = (int) Config::get('max_pdf_mb') * 1024 * 1024;
        if (strlen($bytes) > $maxBytes) {
            throw new SourceException(sprintf('%s: PDF が %dMB を超えています。', $label, (int) Config::get('max_pdf_mb')));
        }
        return ['kind' => 'pdf', 'label' => $label, 'data' => $bytes];
    }

    /** @return array{kind:string,label:string,data:string} */
    public static function text(string $text, string $label, array &$warnings): array
    {
        if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
            $warnings[] = sprintf(
                '%s: 本文が %s 文字あり、先頭 %s 文字のみ使用しました。後半の情報は選定に反映されていません。',
                $label,
                number_format(mb_strlen($text)),
                number_format(self::MAX_TEXT_CHARS)
            );
            $text = mb_substr($text, 0, self::MAX_TEXT_CHARS);
        }
        return ['kind' => 'text', 'label' => $label, 'data' => $text];
    }

    /** @return array{kind:string,label:string,data:string} */
    public static function fetchUrl(string $url, array &$warnings): array
    {
        $current = $url;
        for ($i = 0; $i <= self::MAX_REDIRECTS; $i++) {
            [$status, $headers, $body] = self::httpGet($current);
            if ($status >= 300 && $status < 400 && !empty($headers['location'])) {
                $current = self::resolveRedirect($current, $headers['location']);
                continue;
            }
            if ($status >= 400) {
                throw new SourceException("{$url}: HTTP {$status} で取得できませんでした。");
            }
            $ctype = strtolower($headers['content-type'] ?? '');
            if (str_contains($ctype, 'application/pdf') || strncmp($body, '%PDF-', 5) === 0) {
                return self::pdf($body, $url);
            }
            $text = self::htmlToText(self::decode($body, $ctype));
            if (mb_strlen($text) < 200) {
                $warnings[] = sprintf(
                    '%s: 取得できた本文が %d 文字と少なく、JavaScript で描画されるページの可能性があります。PDF や貼り付けテキストでの入力を検討してください。',
                    $url,
                    mb_strlen($text)
                );
            }
            return self::text($text, $url, $warnings);
        }
        throw new SourceException("{$url}: リダイレクトが多すぎます。");
    }

    /** URL を検証し、公開IPであることを確認して接続先IPを返す。 */
    public static function assertPublicUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
            throw new SourceException("{$url}: URL を解釈できません。");
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new SourceException("{$url}: http/https の URL のみ指定できます。");
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port !== 80 && $port !== 443) {
            throw new SourceException("{$url}: ポート {$port} は指定できません。");
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SourceException("{$url}: 認証情報を含む URL は指定できません。");
        }
        $host = trim($parts['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips) {
            throw new SourceException("{$url}: ホスト名を解決できません。");
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new SourceException("{$url}: 内部ネットワークのアドレスには接続できません。");
            }
        }
        return [$host, $port, $ips[0]];
    }

    /** @return array{0:int,1:array<string,string>,2:string} */
    private static function httpGet(string $url): array
    {
        [$host, $port, $ip] = self::assertPublicUrl($url);
        $headers = [];
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => self::FETCH_TIMEOUT_SEC,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; tagfinder-web/0.1)',
            CURLOPT_HTTPHEADER => ['Accept-Language: ja,en;q=0.8'],
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body): int {
                $body .= $chunk;
                return strlen($body) > Sources::MAX_FETCH_BYTES ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (strlen($body) > self::MAX_FETCH_BYTES) {
            throw new SourceException("{$url}: ページが大きすぎます（5MB 超）。");
        }
        if ($ok === false && $errno !== 0) {
            throw new SourceException("{$url}: 取得に失敗しました（{$error}）。");
        }
        return [$status, $headers, $body];
    }

    private static function resolveRedirect(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($location, '//')) {
            return $p['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = rtrim(dirname($p['path'] ?? '/'), '/');
        return $origin . $dir . '/' . $location;
    }

    public static function decode(string $raw, string $contentType = ''): string
    {
        $candidates = [];
        if (preg_match('/charset=([\w\-]+)/i', $contentType, $m)) {
            $candidates[] = $m[1];
        }
        if (preg_match('/<meta[^>]+charset=["\']?([\w\-]+)/i', substr($raw, 0, 4096), $m)) {
            $candidates[] = $m[1];
        }
        foreach ($candidates as $enc) {
            if (in_array(strtoupper($enc), array_map('strtoupper', mb_list_encodings()), true)) {
                $converted = @mb_convert_encoding($raw, 'UTF-8', $enc);
                if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                    return $converted;
                }
            }
        }
        $detected = mb_detect_encoding($raw, ['UTF-8', 'SJIS-win', 'EUC-JP'], true);
        if ($detected !== false && $detected !== 'UTF-8') {
            return mb_convert_encoding($raw, 'UTF-8', $detected);
        }
        return mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
    }

    public static function htmlToText(string $html): string
    {
        $title = '';
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $description = '';
        if (preg_match_all('#<meta\s[^>]*>#i', $html, $metas)) {
            foreach ($metas[0] as $tag) {
                if (preg_match('#(?:name|property)=["\'](?:og:)?description["\']#i', $tag)
                    && preg_match('#content=["\']([^"\']*)["\']#i', $tag, $c)) {
                    $description = trim(html_entity_decode($c[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    break;
                }
            }
        }
        $body = preg_replace('#<(script|style|noscript|svg|template|head)\b.*?</\1\s*>#is', '', $html) ?? $html;
        $body = preg_replace('#</?(p|div|br|li|ul|ol|tr|table|section|article|header|footer|h[1-6]|dt|dd)\b[^>]*>#i', "\n", $body) ?? $body;
        $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = preg_replace('/[ \t\x{3000}]+/u', ' ', $body) ?? $body;
        $body = trim(preg_replace('/\n\s*\n+/', "\n", $body) ?? $body);

        $head = [];
        if ($title !== '') {
            $head[] = "[title] {$title}";
        }
        if ($description !== '') {
            $head[] = "[description] {$description}";
        }
        $head[] = $body;
        return implode("\n", $head);
    }
}
