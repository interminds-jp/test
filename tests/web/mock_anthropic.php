<?php
// 統合テスト用の偽 Anthropic API（php -S のルータースクリプト）。
// 受け取ったリクエストを検証し、不正なら 400 を返す。MOCK_LOG にリクエストを追記する。
//   本文に "REFUSE" を含むと refusal、"BADJSON" を含むと壊れた JSON を返す。
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$headers = array_change_key_case(getallheaders(), CASE_LOWER);

if (($log = getenv('MOCK_LOG')) !== false) {
    file_put_contents($log, json_encode(['path' => $_SERVER['REQUEST_URI'], 'headers' => $headers, 'body' => $body], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
}

function fail(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['type' => 'error', 'error' => ['type' => $status === 401 ? 'authentication_error' : 'invalid_request_error', 'message' => $message]]);
    exit;
}

if ($path !== '/v1/messages' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(404, "unexpected {$_SERVER['REQUEST_METHOD']} {$path}");
}
// 公式 PHP SDK は API キーを Authorization: Bearer で送る。x-api-key も許容する。
$key = $headers['x-api-key'] ?? preg_replace('/^Bearer\s+/i', '', $headers['authorization'] ?? '');
if ($key !== 'sk-test') {
    fail(401, 'invalid api key');
}
$errors = [];
$errors[] = ($body['model'] ?? '') === 'claude-opus-5-5' ? null : 'model';
$errors[] = ($body['max_tokens'] ?? 0) >= 4000 ? null : 'max_tokens';
$errors[] = ($body['system'][0]['cache_control']['type'] ?? '') === 'ephemeral' ? null : 'system cache_control';
$errors[] = str_contains($body['system'][0]['text'] ?? '', '# タグカタログ') && str_contains($body['system'][0]['text'] ?? '', '2209 | ') ? null : 'system catalog';
$errors[] = ($body['output_config']['format']['type'] ?? '') === 'json_schema' ? null : 'output_config.format';
$errors[] = ($body['output_config']['effort'] ?? '') === 'medium' ? null : 'effort';
$errors[] = isset($body['thinking']) || isset($body['temperature']) ? 'must not send thinking/temperature' : null;
$errors[] = ($body['messages'][0]['role'] ?? '') === 'user' ? null : 'messages';
if (!empty($_SERVER['HTTP_X_EXPECT_FALLBACK'])) {
    $errors[] = ($body['fallbacks'] ?? null) === 'default' ? null : 'fallbacks=default';
    $errors[] = str_contains($headers['anthropic-beta'] ?? '', 'server-side-fallback-2026-07-01') ? null : 'anthropic-beta header';
}
$errors = array_values(array_filter($errors));
if ($errors) {
    fail(400, 'mock validation failed: ' . implode(', ', $errors));
}

$all = json_encode($body['messages'], JSON_UNESCAPED_UNICODE);
$text = (string) file_get_contents($root . '/examples/sample_model_output.json');
$stop = 'end_turn';
$details = null;
if (str_contains($all, 'REFUSE')) {
    $stop = 'refusal';
    $details = ['type' => 'refusal', 'category' => 'cyber', 'explanation' => null, 'fallback_credit_token' => null, 'fallback_has_prefill_claim' => null, 'recommended_model' => null];
    $text = '';
} elseif (str_contains($all, 'BADJSON')) {
    $text = 'これはJSONではありません';
}

header('Content-Type: application/json');
echo json_encode([
    'id' => 'msg_mock_01',
    'type' => 'message',
    'role' => 'assistant',
    'model' => $body['model'],
    'content' => $text === '' ? [] : [['type' => 'text', 'text' => $text]],
    'stop_reason' => $stop,
    'stop_sequence' => null,
    'stop_details' => $details,
    'usage' => ['input_tokens' => 12345, 'output_tokens' => 678, 'cache_creation_input_tokens' => 11000, 'cache_read_input_tokens' => 0],
], JSON_UNESCAPED_UNICODE);
