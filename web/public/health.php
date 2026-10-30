<?php
declare(strict_types=1);

// デプロイ確認用。機密値は返さない（設定の有無のみ）。
// 公開ディレクトリを分離できる場合は ../app、できない場合（docroot 直下に配置）は ./app を使う
require_once is_file(__DIR__ . '/app/bootstrap.php') ? __DIR__ . '/app/bootstrap.php' : __DIR__ . '/../app/bootstrap.php';

send_security_headers();

$checks = [
    'php_version' => PHP_VERSION,
    'php_ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'ext_curl' => extension_loaded('curl'),
    'ext_mbstring' => extension_loaded('mbstring'),
    'ext_openssl' => extension_loaded('openssl'),
    'sdk_installed' => class_exists('Anthropic\\Client'),
    'var_writable' => is_writable(Config::varDir()),
    'password_set' => Auth::isConfigured(),
    'api_key_set' => (string) Config::get('anthropic_api_key') !== '',
    'mock_enabled' => (bool) Config::get('allow_mock'),
];

try {
    $master = TagMaster::load();
    $checks['tags_loaded'] = count($master->tags);
    $checks['tags_ok'] = count($master->tags) === 287;
    $checks['shared_ok'] = Selector::systemPromptHead() !== '' && is_array(Selector::outputSchema());
} catch (Throwable $e) {
    $checks['tags_loaded'] = 0;
    $checks['tags_ok'] = false;
    $checks['shared_ok'] = false;
}

// 起動に必須の項目がそろっているか（api_key_set / mock_enabled は運用段階で変わるため必須にしない）
$required = ['php_ok', 'ext_curl', 'ext_mbstring', 'ext_openssl', 'sdk_installed', 'var_writable', 'tags_ok', 'shared_ok'];
$ok = true;
foreach ($required as $k) {
    $ok = $ok && !empty($checks[$k]);
}

json_response(['ok' => $ok, 'app' => 'tagfinder-web', 'checks' => $checks], $ok ? 200 : 503);
