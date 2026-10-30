<?php
declare(strict_types=1);

// 使い方: php tests/web/run_tests.php   （事前に deploy/build.sh は不要。web/vendor を使う）
$root = dirname(__DIR__, 2);
require_once $root . '/web/app/bootstrap.php';
require_once $root . '/web/vendor/autoload.php';

$failures = 0;
$total = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $failures, $total;
    $total++;
    if ($cond) {
        echo "  ok   {$name}\n";
    } else {
        $failures++;
        echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function throws(callable $fn, string $class, ?string $contains = null): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class && ($contains === null || str_contains($e->getMessage(), $contains));
    }
    return false;
}

$master = TagMaster::load();

echo "[TagMaster]\n";
$byKind = [];
foreach ($master->tags as $t) {
    $byKind[$t['kind']] = ($byKind[$t['kind']] ?? 0) + 1;
}
ksort($byKind);
check('287 tags', count($master->tags) === 287);
check('kind counts match headings', $byKind === ['activity' => 89, 'field' => 142, 'intent' => 25, 'new_move' => 10, 'trend' => 21]);
check('resolve normalizes id', ($master->resolve(' ita0a00 ')['id'] ?? null) === 'ITA0A00' && ($master->resolve(2209)['id'] ?? null) === '2209' && $master->resolve('9999') === null);
check('resolve rejects non-scalar', $master->resolve(['x']) === null && $master->resolve(null) === null);

// Python 版（tagfinder）が生成するカタログ文字列と一致すること（プロンプトの二重管理によるずれを検知）
$pyCatalog = null;
if (PHP_OS_FAMILY !== 'Windows' && trim((string) shell_exec('command -v python3')) !== '') {
    $pyCatalog = shell_exec('cd ' . escapeshellarg($root) . ' && python3 -c "import sys; from tagfinder.tags import TagMaster; sys.stdout.write(TagMaster.load().catalog_text())" 2>/dev/null');
}
if (is_string($pyCatalog) && $pyCatalog !== '') {
    check('catalog text identical to Python', $master->catalogText() === $pyCatalog);
} else {
    echo "  skip catalog parity (python3 を実行できません)\n";
}

echo "[Query]\n";
$backend = new MockCountBackend();
$warnings = [];
[$must, $should, $exclude] = Query::normalize([
    'must' => [['tag_id' => '2209', 'reason' => 'a'], ['tag_id' => '2209', 'reason' => 'dup'], ['tag_id' => '9999', 'reason' => 'x']],
    'should' => [['tag_id' => '5209', 'weight' => 99, 'reason' => 'y'], ['tag_id' => '2209', 'weight' => 2, 'reason' => 'z'], ['tag_id' => '1102', 'weight' => 2, 'reason' => 'w']],
    'exclude' => [['tag_id' => '2209', 'reason' => ''], ['tag_id' => '1102', 'reason' => '']],
], $master, $warnings);
check('must dedup + unknown dropped', array_column($must, 'tag_id') === ['2209']);
check('exclude loses to must', array_column($exclude, 'tag_id') === ['1102']);
check('should drops overlaps, clamps weight', array_column($should, 'tag_id') === ['5209'] && $should[0]['weight'] === 5);
check('warnings emitted', count($warnings) >= 3);

$res = $backend->count($master, [['tag_id' => '2209', 'reason' => '', 'weight' => 0]], [], []);
check('single must estimate = tag count', $res['estimate'] === $master->byId['2209']['count']);
check('empty query -> null', $backend->count($master, [], [], [])['estimate'] === null);

[$m2, $s2, $steps, $r2] = Query::relaxUntil($master, $backend, [
    ['tag_id' => '2209', 'reason' => 'big', 'weight' => 0],
    ['tag_id' => '5209', 'reason' => 'small', 'weight' => 0],
    ['tag_id' => '3022', 'reason' => 'huge', 'weight' => 0],
], [], [], 300);
check('relax reaches target', $r2['estimate'] >= 300);
check('relax demotes smallest first', ($steps[0]['demoted_tag_id'] ?? null) === '5209');
check('demoted tag moved to should', in_array('5209', array_column($s2, 'tag_id'), true));

$zero = new class implements CountBackend {
    public function count(TagMaster $m, array $must, array $should, array $exclude): array
    {
        return ['estimate' => 0, 'method' => 'zero'];
    }
};
$q = Query::build(['tag_selection' => ['must' => [['tag_id' => '2209', 'reason' => '']]]], $master, $zero, 100);
check('gives up with warning', $q['must'] === [] && str_contains(implode('|', $q['warnings']), '届きませんでした'));

echo "[Analyzer (mock)]\n";
Config::override(['model' => 'claude-opus-5-5', 'min_count' => 300, 'allow_mock' => true]);
$out = Analyzer::run([], '', [], ['mock' => true]);
check('mock estimate matches Python (41229)', $out['query']['estimate']['count'] === 41229, (string) $out['query']['estimate']['count']);
check('mock flagged in meta', $out['meta']['mock'] === true);
$weights = array_column($out['query']['should'], 'weight');
$sorted = $weights;
rsort($sorted);
check('should sorted by weight', $weights === $sorted);

echo "[Selector]\n";
$sources = [['kind' => 'text', 'label' => 'http://example.com', 'data' => '本文'], ['kind' => 'pdf', 'label' => 'a.pdf', 'data' => '%PDF-1.4 test']];
$req = Selector::buildRequest($master, $sources, 'ヒント', 'claude-opus-5-5', 'medium');
$types = array_column($req['messages'][0]['content'], 'type');
check('pdf first, then text, then instruction', $types === ['document', 'text', 'text']);
check('hint appended', str_contains(end($req['messages'][0]['content'])['text'], 'ヒント'));
check('system cached', ($req['system'][0]['cacheControl']['type'] ?? '') === 'ephemeral');
check('structured output requested', ($req['outputConfig']['format']['type'] ?? '') === 'json_schema');
check('no thinking/temperature', !isset($req['thinking']) && !isset($req['temperature']));
check('schema equals shared file', $req['outputConfig']['format']['schema'] === json_decode(file_get_contents($root . '/shared/output_schema.json'), true));

$mk = fn(?string $text, string $stop = 'end_turn', ?string $cat = null) => (object) [
    'stopReason' => $stop,
    'stopDetails' => (object) ['category' => $cat],
    'content' => $text === null ? [] : [(object) ['type' => 'text', 'text' => $text]],
    'usage' => (object) ['inputTokens' => 1, 'outputTokens' => 2, 'cacheCreationInputTokens' => 0, 'cacheReadInputTokens' => 0],
];
check('parse ok', Selector::parse($mk('{"a":1}')) === ['a' => 1]);
check('parse refusal', throws(fn() => Selector::parse($mk(null, 'refusal', 'cyber')), SelectionException::class, 'cyber'));
check('parse max_tokens', throws(fn() => Selector::parse($mk('{}', 'max_tokens')), SelectionException::class));
check('parse bad json', throws(fn() => Selector::parse($mk('not json')), SelectionException::class));
check('parse no text', throws(fn() => Selector::parse($mk(null)), SelectionException::class));
check('call without key fails clearly', throws(function () use ($req) {
    Config::override(['anthropic_api_key' => '']);
    putenv('ANTHROPIC_API_KEY');
    Selector::call($req, true);
}, SelectionException::class, 'API キー'));

echo "[Sources]\n";
$html = "<html><head><title>製品A</title><meta name='description' content='説明文'><style>.x{}</style></head><body><script>var a=1;</script><h1>見出し</h1><p>本文です</p></body></html>";
$text = Sources::htmlToText($html);
check('title + description kept', str_contains($text, '[title] 製品A') && str_contains($text, '[description] 説明文'));
check('script/style removed', !str_contains($text, 'var a') && !str_contains($text, '.x{}'));
check('body text kept', str_contains($text, '見出し') && str_contains($text, '本文です'));
$sjis = mb_convert_encoding('<html><head><meta charset="Shift_JIS"></head><body>日本語テキスト</body></html>', 'SJIS-win', 'UTF-8');
check('decodes Shift_JIS', str_contains(Sources::decode($sjis, 'text/html'), '日本語テキスト'));
foreach (['http://127.0.0.1/', 'http://10.0.0.5/', 'http://169.254.169.254/latest/meta-data/', 'http://192.168.1.1/', 'http://[::1]/', 'http://localhost/'] as $u) {
    check("SSRF blocked: {$u}", throws(fn() => Sources::assertPublicUrl($u), SourceException::class));
}
check('non-http scheme blocked', throws(fn() => Sources::assertPublicUrl('file:///etc/passwd'), SourceException::class));
check('ftp blocked', throws(fn() => Sources::assertPublicUrl('ftp://example.com/'), SourceException::class));
check('odd port blocked', throws(fn() => Sources::assertPublicUrl('http://example.com:8080/'), SourceException::class, 'ポート'));
check('userinfo blocked', throws(fn() => Sources::assertPublicUrl('http://user:pw@example.com/'), SourceException::class));
check('pdf magic checked', throws(fn() => Sources::pdf('not pdf', 'x.pdf'), SourceException::class));
check('pdf size limit', throws(function () {
    Config::override(['max_pdf_mb' => 1]);
    Sources::pdf('%PDF-' . str_repeat('a', 1024 * 1024 + 1), 'big.pdf');
}, SourceException::class, '超えて'));
$w = [];
$long = Sources::text(str_repeat('あ', Sources::MAX_TEXT_CHARS + 10), 'x', $w);
check('long text truncated with warning', mb_strlen($long['data']) === Sources::MAX_TEXT_CHARS && count($w) === 1);

echo "\n{$total} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);
