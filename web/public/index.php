<?php
declare(strict_types=1);

// 公開ディレクトリを分離できる場合は ../app、できない場合（docroot 直下に配置）は ./app を使う
require_once is_file(__DIR__ . '/app/bootstrap.php') ? __DIR__ . '/app/bootstrap.php' : __DIR__ . '/../app/bootstrap.php';

send_security_headers();
header('Cache-Control: no-store');
Auth::startSession();

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $status = Auth::login((string) $_POST['password']);
    if ($status === 'ok') {
        header('Location: ./', true, 303);
        exit;
    }
    $loginError = [
        'invalid' => 'パスワードが違います。',
        'locked' => '失敗が続いたため、しばらくロックしています。15分後に再試行してください。',
        'not_configured' => 'パスワードが未設定です。サーバー側の設定（app/config.php の password_hash）を確認してください。',
    ][$status] ?? 'ログインできませんでした。';
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$loggedIn = Auth::isLoggedIn();
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>営業先タグ選定 | click.interminds.work</title>
  <link rel="stylesheet" href="assets/style.css">
<?php if ($loggedIn): ?>
  <meta name="csrf-token" content="<?= h(Auth::csrfToken()) ?>">
  <meta name="allow-mock" content="<?= Config::get('allow_mock') ? '1' : '0' ?>">
<?php endif; ?>
</head>
<body>
<?php if (!$loggedIn): ?>
  <main class="login">
    <h1>営業先タグ選定</h1>
    <p class="muted">商品のURL・PDFから、DataPark タグの営業先検索条件を選定します。</p>
    <form method="post" action="./" autocomplete="off">
      <label for="password">パスワード</label>
      <input type="password" id="password" name="password" required autofocus>
      <?php if ($loginError !== ''): ?><p class="error" role="alert"><?= h($loginError) ?></p><?php endif; ?>
      <button type="submit" class="primary">ログイン</button>
    </form>
  </main>
<?php else: ?>
  <header class="top">
    <h1>営業先タグ選定</h1>
    <button type="button" id="logout" class="ghost">ログアウト</button>
  </header>
  <main class="layout">
    <section class="panel">
      <h2>商品資料を入力</h2>
      <form id="analyze-form">
        <label for="urls">商品ページのURL（1行に1件・3件まで）</label>
        <textarea id="urls" name="urls" rows="3" placeholder="https://example.com/product"></textarea>

        <label for="pdf">PDFカタログ（3件まで・各<?= (int) Config::get('max_pdf_mb') ?>MB以内）</label>
        <input type="file" id="pdf" name="pdf[]" accept="application/pdf" multiple>

        <label for="text">商品説明（貼り付け）</label>
        <textarea id="text" name="text" rows="5" placeholder="商品の特徴・用途・価格帯・想定顧客など"></textarea>

        <label for="hint">追加の前提（任意）</label>
        <input type="text" id="hint" name="hint" maxlength="2000" placeholder="例：従業員300人以下の製造業のみを対象にする">

        <?php if (Config::get('allow_mock')): ?>
        <label class="check"><input type="checkbox" id="mock" name="mock" value="1"> モック応答で試す（API を呼ばず、サンプル出力を使う）</label>
        <?php endif; ?>

        <div class="actions">
          <button type="submit" id="submit" class="primary">タグを選定する</button>
          <span id="status" class="muted" role="status"></span>
        </div>
      </form>
      <p class="muted small">解析には数十秒かかることがあります。URL のページが JavaScript で描画される場合は、本文が取れないことがあります（PDF か貼り付けでの入力をお試しください）。</p>
    </section>

    <section class="panel" id="result-panel" hidden>
      <div class="result-head">
        <h2>選定結果</h2>
        <button type="button" id="download" class="ghost">JSON を保存</button>
      </div>
      <div id="result"></div>
    </section>
  </main>
  <script src="assets/app.js"></script>
<?php endif; ?>
</body>
</html>
