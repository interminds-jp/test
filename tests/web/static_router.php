<?php
// 統合テスト用のルータースクリプト（php -S）。
// 組み込みサーバーは存在しないパスに index.php を返すため、verify.sh の [4] が
// 「200 = 公開されている」と誤検知する。存在しないパスは Apache と同じく 404 にする。
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (file_exists($_SERVER['DOCUMENT_ROOT'] . $path)) {
    return false;
}
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not Found\n";
