<?php
// deploy.sh init-config から呼ばれる。秘密情報は引数ではなく環境変数で受け取る（ps に出さない）。
//   TF_PASSWORD  ログインパスワード（必須）
//   TF_API_KEY   Anthropic API キー（空ならモック運用）
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$password = getenv('TF_PASSWORD') ?: '';
$apiKey = getenv('TF_API_KEY') ?: '';
if ($password === '') {
    fwrite(STDERR, "TF_PASSWORD が空です\n");
    exit(1);
}
$config = [
    'anthropic_api_key' => $apiKey,
    'model' => 'claude-opus-5-5',
    'effort' => 'medium',
    'use_fallback' => true,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'allow_mock' => $apiKey === '',
    'min_count' => 300,
    'rate_limit_per_hour' => 30,
    'max_pdf_mb' => 10,
];
echo "<?php\n// deploy.sh init-config が生成。git 管理しない。\nreturn " . var_export($config, true) . ";\n";
