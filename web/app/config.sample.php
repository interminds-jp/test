<?php
// このファイルを config.php にコピーして編集する（config.php は git 管理しない）。
// ANTHROPIC_API_KEY は環境変数でも渡せる（config の値が空のときに環境変数を使う）。
return [
    'anthropic_api_key' => '',
    'model' => 'claude-opus-5-5',
    'effort' => 'medium',
    'use_fallback' => true,
    // php deploy/gen-password-hash.php 'パスワード' の出力を貼る
    'password_hash' => '',
    // true のとき、画面から「モック応答で試す」が使える（API キー無しの疎通確認用）
    'allow_mock' => false,
    'min_count' => 300,
    // 1時間あたりの解析回数の上限（全体）
    'rate_limit_per_hour' => 30,
    'max_pdf_mb' => 10,
];
