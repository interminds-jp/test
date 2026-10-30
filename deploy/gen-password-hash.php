<?php
// 使い方: php deploy/gen-password-hash.php 'パスワード'
// 出力された文字列を app/config.php の password_hash に貼る。
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$pw = $argv[1] ?? '';
if ($pw === '') {
    fwrite(STDERR, "使い方: php deploy/gen-password-hash.php 'パスワード'\n");
    exit(1);
}
echo password_hash($pw, PASSWORD_DEFAULT), "\n";
