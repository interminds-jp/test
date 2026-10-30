# click.interminds.work への展開

商品URL/PDF → DataPark タグ選定の Web 画面（PHP）を、XSERVER などの Apache + PHP 環境に展開する手順です。

```
web/public   公開ファイル（index.php / api.php / health.php / assets）
web/app      アプリ本体（lib・設定・var）  ← Web から見えない（.htaccess で拒否）
data/tags.json, shared/*, examples/*  → ビルド時に app/ へ同梱
vendor/      Anthropic 公式 PHP SDK ほか（composer）
```

## 前提

- サーバー: PHP 8.1 以上（拡張 `curl` / `mbstring` / `openssl`）、Apache（`.htaccess` が有効）、SSH 接続
- 手元（Mac）: `php`、`composer`、`rsync`、`ssh`（Homebrew: `brew install php composer`）
- Anthropic API キー（`sk-ant-...`）

## 手順（XSERVER の場合）

1. **ドメイン／サブドメイン**: サーバーパネルで `click.interminds.work` を設定し、「無料独自SSL」を有効にする（HTTPS 必須。`.htaccess` が HTTP を HTTPS へ転送します）。
2. **PHP バージョン**: サーバーパネルの「PHP Ver.切替」で `click.interminds.work` を 8.1 以上にする。
3. **SSH**: サーバーパネルで SSH を ON にし、鍵ペアを作成して秘密鍵（`.key`）を保存する。ポートは `10022`。
4. **ビルドして送信**（Mac で、リポジトリの直下から）:

   ```bash
   export DEPLOY_HOST=svXXXX.xserver.jp          # サーバーパネルに表示されるホスト名
   export DEPLOY_USER=xsXXXXXX                   # サーバーID
   export DEPLOY_PORT=10022
   export DEPLOY_KEY=~/.ssh/xserver.key
   export DEPLOY_PATH=/home/xsXXXXXX/click.interminds.work/public_html   # ドキュメントルート
   # DEPLOY_LAYOUT は既定で flat（ドキュメントルート直下に配置し、app/ と vendor/ は .htaccess で保護）

   DRY_RUN=1 deploy/deploy.sh upload   # 先に送信内容だけ確認したい場合（要 build）
   deploy/deploy.sh all                # ビルド + 送信
   ```

   ドキュメントルートを `public/` に変更できるホスティングでは `DEPLOY_LAYOUT=split`、`DEPLOY_PATH` をサイトの親ディレクトリにする（この場合 `app/` と `vendor/` は公開外になり、より安全）。

5. **設定ファイルの作成**（初回のみ。パスワードと API キーは対話入力。画面にも履歴にも残りません）:

   ```bash
   deploy/deploy.sh init-config
   ```

   API キーを空欄にすると、`app/config.php` はモック運用（API を呼ばないサンプル表示）になります。あとから API キーを入れる場合は、もう一度 `init-config` を実行してください。

6. **動作確認**:

   ```bash
   deploy/verify.sh https://click.interminds.work                       # 公開状態の確認（ログイン不要の項目）
   VERIFY_PASSWORD='設定したパスワード' deploy/verify.sh https://click.interminds.work   # ログイン〜解析まで
   # 実 API での解析まで確認する（API 利用料が少額発生）
   VERIFY_PASSWORD='...' VERIFY_LIVE_TEXT='製造業向けの検査装置…' deploy/verify.sh https://click.interminds.work
   ```

   `verify.sh` は次を確認します: ヘルスチェック／画面表示／HTTP→HTTPS転送／`app/`・`vendor/`・設定ファイルが**公開されていないこと**／未ログインで API が使えないこと／ログイン・CSRF／モック解析／（任意）実 API 解析。

## 更新・運用

- コードを更新したら `deploy/deploy.sh all`。サーバー側の `app/config.php` と `app/var/`（レート制限の記録）は上書き・削除されません。
- PHP のエラーはサーバーのエラーログに `[tagfinder]` 付きで出ます（XSERVER はサーバーパネルの「エラーログ」）。
- 解析は 1 回あたり数十秒かかることがあります。タイムアウトする場合は、サーバーの PHP 設定（`max_execution_time`）を 180 秒以上にしてください。
- PDF のアップロード上限は、サーバーの `upload_max_filesize` / `post_max_size` に従います（`.htaccess` の指定が効かない環境では、サーバーパネルの PHP 設定で変更）。

## セキュリティ

- ログインはパスワード（`password_hash`）＋セッション。失敗が続くと15分ロック。解析は1時間あたり30回まで（`config.php` で変更可）。
- 商品URLの取得は、公開IPのみ・ポート80/443のみ・リダイレクト先も再検証（SSRF 対策）。
- 画面の描画は `textContent` のみで、モデル出力や外部ページの文字列を HTML として解釈しない。CSP も設定済み。
- API キーとパスワードは `app/config.php`（権限 600）にのみ置く。リポジトリには含めない。
- **データの取り扱い**: `data/tags.json` は NBS/DataPark 由来の資料です。サーバーに置くため、利用許諾・公開範囲を確認してください。

## 未実装

- DataPark 実データへの接続（現在の推定件数はタグ間の独立を仮定した概算）。`web/app/lib/Query.php` の `CountBackend` が差し替え口です。
