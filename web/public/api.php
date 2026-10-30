<?php
declare(strict_types=1);

// 公開ディレクトリを分離できる場合は ../app、できない場合（docroot 直下に配置）は ./app を使う
require_once is_file(__DIR__ . '/app/bootstrap.php') ? __DIR__ . '/app/bootstrap.php' : __DIR__ . '/../app/bootstrap.php';

send_security_headers();
Auth::startSession();

$action = $_GET['action'] ?? 'analyze';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST で呼び出してください。'], 405);
}
if (!Auth::isLoggedIn()) {
    json_response(['ok' => false, 'error' => 'ログインが必要です。', 'code' => 'unauthorized'], 401);
}
if (!Auth::checkCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    json_response(['ok' => false, 'error' => 'セッションが無効です。ページを再読み込みしてください。', 'code' => 'csrf'], 403);
}

if ($action === 'logout') {
    Auth::logout();
    json_response(['ok' => true]);
}
if ($action !== 'analyze') {
    json_response(['ok' => false, 'error' => '不明な操作です。'], 400);
}

/** @return list<array{name:string,tmp_name:string,error:int,size:int}> */
function uploaded_pdfs(): array
{
    if (empty($_FILES['pdf'])) {
        return [];
    }
    $f = $_FILES['pdf'];
    $files = [];
    if (is_array($f['name'])) {
        foreach ($f['name'] as $i => $name) {
            $files[] = ['name' => (string) $name, 'tmp_name' => (string) $f['tmp_name'][$i], 'error' => (int) $f['error'][$i], 'size' => (int) $f['size'][$i]];
        }
    } else {
        $files[] = ['name' => (string) $f['name'], 'tmp_name' => (string) $f['tmp_name'], 'error' => (int) $f['error'], 'size' => (int) $f['size']];
    }
    return array_values(array_filter($files, fn($x) => $x['error'] !== UPLOAD_ERR_NO_FILE));
}

@set_time_limit(180);

try {
    $warnings = [];
    $sources = [];
    $mock = ($_POST['mock'] ?? '') === '1';
    if ($mock && !Config::get('allow_mock')) {
        json_response(['ok' => false, 'error' => 'モック応答は無効化されています。'], 403);
    }

    $hint = trim((string) ($_POST['hint'] ?? ''));
    if (mb_strlen($hint) > 2000) {
        throw new SourceException('追加の前提は2000文字以内にしてください。');
    }

    if (!$mock) {
        $urls = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($_POST['urls'] ?? '')) ?: [])));
        if (count($urls) > Sources::MAX_URLS) {
            throw new SourceException(sprintf('URL は %d 件までです。', Sources::MAX_URLS));
        }
        $pdfs = uploaded_pdfs();
        if (count($pdfs) > Sources::MAX_PDFS) {
            throw new SourceException(sprintf('PDF は %d 件までです。', Sources::MAX_PDFS));
        }
        $text = trim((string) ($_POST['text'] ?? ''));
        if (!$urls && !$pdfs && $text === '') {
            throw new SourceException('URL・PDF・商品説明のいずれかを入力してください。');
        }

        foreach ($pdfs as $p) {
            if ($p['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($p['tmp_name'])) {
                throw new SourceException(sprintf('%s: アップロードに失敗しました（サイズ上限を超えた可能性があります）。', $p['name']));
            }
            $bytes = (string) file_get_contents($p['tmp_name']);
            $sources[] = Sources::pdf($bytes, $p['name']);
        }
        foreach ($urls as $u) {
            $sources[] = Sources::fetchUrl($u, $warnings);
        }
        if ($text !== '') {
            $sources[] = Sources::text($text, '(直接入力)', $warnings);
        }

        if (!RateLimit::consumeAnalysis()) {
            json_response(['ok' => false, 'error' => '解析回数の上限（1時間あたり）に達しました。しばらくしてから再試行してください。', 'code' => 'rate_limited'], 429);
        }
    }

    $result = Analyzer::run($sources, $hint, $warnings, ['mock' => $mock]);
    json_response(['ok' => true, 'result' => $result]);
} catch (SourceException $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (SelectionException $e) {
    error_log('[tagfinder] ' . $e->getMessage() . ($e->getPrevious() ? ' / ' . get_class($e->getPrevious()) : ''));
    json_response(['ok' => false, 'error' => $e->getMessage()], 502);
} catch (Throwable $e) {
    error_log('[tagfinder] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_response(['ok' => false, 'error' => '内部エラーが発生しました。サーバーのログを確認してください。'], 500);
}
