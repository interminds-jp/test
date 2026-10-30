# 引き継ぎプロンプト（Mac mini の Claude Code 用）

以下の「プロンプト本文」を、Mac mini で Claude Code を起動したあと、最初のメッセージとして貼り付けてください。

---

## プロンプト本文

あなたは、前のセッション（クラウド環境）から作業を引き継ぎます。以下を前提に、「やること」を順に進めてください。回答は日本語で、簡潔に。ただしソースコードや出力は省略しないでください。

### 1. 目的

商品のURL・PDFカタログから営業先の条件を抽出し、最終的に **DataPark（運営: NBS）が持つ企業データ（マーケティングタグ）を、営業リストとして売れる仕組み**にする。入口は「売りたい商品を入れたら、営業先リストの検索条件ができる」こと。

直近のゴールは、**`https://click.interminds.work/` に Web 環境を展開し、確認できる状態にすること**。前のセッションはネットワーク制限と認証情報の不足で、展開だけが未実施。

### 2. 現在の状態

リポジトリ: `interminds-jp/test`（GitHub）。3本のドラフトPRが積み重なっている。

| PR | ブランチ | ベース | 内容 |
|---|---|---|---|
| #2 | `claude/datapark-tag-master` | `claude/postgresql-install-tw16wc` | タグマスタ `data/tags.json`（287タグ） |
| #3 | `claude/tag-selector-prototype` | #2 のブランチ | Python CLI `tagfinder/`（商品資料 → タグ選定） |
| #4 | `claude/web-click-deploy` | #3 のブランチ | PHP の Web 画面・API（`web/`）、共通プロンプト（`shared/`）、デプロイ一式（`deploy/`） |

作業は **`claude/web-click-deploy`** で行う（#2・#3 の内容を含む）。マージ順は #2 → #3 → #4。なお、リポジトリの既定ブランチは PostgreSQL 用の `claude/postgresql-install-tw16wc` で、今回の作業とは無関係。

#### できていること（ローカルで検証済み）

- タグマスタ: Excel（PDF を変換したもの）を原本PDFの画像と全287行照合し、ID・社数が全件一致。内訳は活動89／インテント25／新しい動き10／トレンド21／分野142。DataPark の資料由来のデータ。
- Python CLI: Claude が構造化出力で must（AND）／should（重み1〜5）／exclude を理由付きで選ぶ。タグ番号はカタログと照合し、未知番号・重複・矛盾を解消。推定件数が目標未満なら、社数最小の must を should に降格して緩める。
- Web（PHP 8.1+、公式 Anthropic PHP SDK）: パスワードログイン、CSRF、CSP、SSRF 対策付きの URL 取得、PDF アップロード、ログイン試行ロック、解析回数の上限。`web/public`（公開）と `web/app`（非公開）に分離。
- プロンプトとスキーマは `shared/` を Python と PHP で共有。カタログ文字列が一致することをテストで確認済み。
- テスト: Python 18件、PHP 47件、偽 Anthropic API を使う統合テスト（`tests/web/integration.sh`、`verify.sh` が20項目 OK）。画面はデスクトップ・スマホ幅で確認済み。

#### できていないこと

1. **`click.interminds.work` への展開**（最優先）。
2. **実際の Anthropic API での通し実行**。前の環境に API キーがなく、偽 API でのみ確認済み。サンプル出力 `examples/sample_model_output.json` は手書きで、モデルの実出力ではない。
3. **Apache の `.htaccess` による保護**（`app/`・`vendor/` が公開されないこと）の実機確認。PHP 組み込みサーバーは `.htaccess` を無視するため、ローカルでは検証できない。
4. **DataPark 実データへの接続**。現在の推定件数は、タグ間の独立を仮定したモック（`web/app/lib/Query.php` の `MockCountBackend`、Python 側は `tagfinder/counts.py`）。差し替え口は `CountBackend`。API/DB/CSV のどれで取得できるかが未確定。

### 3. やること（順番どおり）

#### 手順 A: 環境を整える

1. `git clone` するか、すでにあれば `git fetch` して `claude/web-click-deploy` をチェックアウトする。
2. 必要なもの: `php`（8.1 以上）、`composer`、`rsync`、`ssh`、`python3`（3.9 以上）。不足していれば Homebrew で入れる（`brew install php composer`）。
3. `cd web && composer install` で `web/vendor/` を作る（`web/vendor/` は git 管理外）。
4. テストを実行して、すべて通ることを確認する。
   - `python3 -m unittest discover -s tests`
   - `php tests/web/run_tests.php`
   - `tests/web/integration.sh`

#### 手順 B: 実 API での確認

1. ユーザーに、環境変数 `ANTHROPIC_API_KEY` を設定してもらう（**キーをチャットに貼らせない**）。
2. 実際の商品ページまたは PDF で、CLI を実行する: `python3 -m tagfinder <URL か PDF> --out result.json`
3. 初回で API エラーが出る場合は、`--no-fallback` を付けて切り分ける。Web 側は `config.php` の `use_fallback` を `false` にする。
4. 出力の品質を一緒に見て、`shared/system_prompt.txt` を調整する。**買い手のタイプが違う商品を2つ**試す（「企業が使うソフト」と「企業が取り扱う製品」）。

#### 手順 C: `click.interminds.work` への展開

1. 先にユーザーへ確認する: ホスティング（XSERVER か）、サーバーID・ホスト名、SSH 設定の有無と秘密鍵の場所、ドキュメントルートのパス、ドキュメントルートを `public/` に変更できるか、PHP バージョン、独自SSLの有効化状況。
2. `deploy/README.md` の手順に従う。
   - `deploy/deploy.sh all`（`DRY_RUN=1 deploy/deploy.sh upload` で先に送信内容を確認できる）
   - `deploy/deploy.sh init-config`（パスワードと API キーは対話入力。履歴に残さない）
   - `deploy/verify.sh https://click.interminds.work`（`VERIFY_PASSWORD` でログインまで、`VERIFY_LIVE_TEXT` で実 API 解析まで確認）
3. **本番サーバーへの送信と設定変更は、実行前にユーザーに確認する。**
4. `verify.sh` の失敗項目を直す。特に次の点を確認する。
   - `app/`・`vendor/`・`app/config.php` が公開されていない（`verify.sh` の [4]）
   - `curl`・`mbstring`・`openssl` 拡張と、PHP 8.1 以上
   - PDF アップロード上限（`upload_max_filesize` / `post_max_size`）
   - 解析のタイムアウト（`max_execution_time` を 180 秒以上に）
   - HTTP から HTTPS への転送
5. 完了条件: `verify.sh` が全項目 OK（実 API 解析まで）で、ブラウザからログインして解析結果が表示される。

#### 手順 D: その後

- DataPark の実データ接続: データの提供形態（API／DB／CSV）、取得できる企業項目（社名・住所・電話・従業員数・業種コードなど）、件数のみの問い合わせができるかを、ユーザー経由で NBS に確認する。決まれば `CountBackend` の実装を差し替える。
- NBS／DataPark とのデータ利用・再販の取り決めの確認（事業化の前提）。`data/tags.json` を載せたサーバーとリポジトリの公開範囲もあわせて確認する。
- 3本のPRのレビューとマージ。

### 4. 実装上の注意（前のセッションで分かったこと）

- モデルは `claude-opus-5-5`。`thinking` や `temperature` は送らない（thinking は常時有効、強制 `tool_choice` は不可）。effort は `medium`。構造化出力は `output_config.format`（`json_schema`）。
- 拒否時のサーバー側フォールバックは `beta.messages.create` の `betas: ['server-side-fallback-2026-07-01']` と `fallbacks: 'default'`。実 API では未検証。
- **公式 PHP SDK（v1.3.0）は API キーを `Authorization: Bearer` で送る**。偽 API の検証で `x-api-key` のみを見ていて失敗した経緯がある。SDK が必要とする PSR-18 クライアントとして `guzzlehttp/guzzle` を `web/composer.json` に入れている。
- PHP では、名前付き引数の後ろでの展開（spread）は不可。`create(...$request, betas: ..., fallbacks: ...)` の順にする。
- `composer install` が git から取得する環境では、`vendor/` に `.git` が混入する。`deploy/build.sh` が除去している。本番へ上げないこと。
- `web/composer.json` は `config.platform.php` を `8.1.99` に固定している（サーバーが PHP 8.1 でも動く依存にするため）。
- 公開ファイルの読み込みは、`./app/bootstrap.php`（docroot 直下に配置した場合）と `../app/bootstrap.php`（`public/` を docroot にした場合）の両対応。`deploy.sh` の `DEPLOY_LAYOUT` が `flat`（既定）と `split` に対応する。
- `app/config.php`（秘密情報）と `app/var/` はサーバー側にのみ置く。`deploy.sh` は上書き・削除しない。`.gitignore` 済みで、リポジトリには絶対に入れない。
- DataPark のサイト `https://datapark.jp` は前の環境からは参照できなかった。事業内容の把握は検索結果（NBS が運営、約80万社・3,000万件超のデータ、Beegle Data がもとのサービス）によるもの。

### 5. 作業ルールと環境

- ユーザーの開発環境は Mac。使っているサーバーは主に XSERVER（Linux）で、別にローカルの Ubuntu もある。
- API キー・パスワード・秘密鍵をチャットに出力させない、コミットしない、コマンドの引数に直接書かない。
- 本番への送信・設定変更・コミット／プッシュなど、元に戻しにくい操作は、実行前にユーザーへ確認する。
- 「途中記録」と指示されたら、その時点までの作業をまとめて Obsidian に記録する。「完了記録」と指示されたら、セッション全体をまとめて Obsidian に記録し、あわせてコンテキストを圧縮する。保存先が不明な場合は質問する。
- 検証していないことを「できた」と報告しない。テストの結果は、通った件数と失敗した件数をそのまま伝える。

### 6. 最初にユーザーへ聞くこと

1. ホスティングは XSERVER か。ホスト名・サーバーID・SSH の状況は。
2. `ANTHROPIC_API_KEY` は Mac mini に設定済みか。
3. 最初に試す商品（URL か PDF）はどれにするか。

---

## 補足（ユーザー向け）

- 引き継ぎ用の作業は `claude/web-click-deploy` ブランチで行います。PR #4 から取得できます。
- 上の「プロンプト本文」には、秘密情報は含まれていません。
