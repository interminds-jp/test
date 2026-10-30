#!/usr/bin/env bash
# click.interminds.work へのデプロイ（SSH + rsync）。
#
#   deploy/deploy.sh all            ビルドして送信
#   deploy/deploy.sh build          dist/ を作るだけ
#   deploy/deploy.sh upload         dist/ を送るだけ（DRY_RUN=1 で送信内容の確認のみ）
#   deploy/deploy.sh init-config    サーバーに app/config.php を作る（パスワード・API キーを対話入力）
#
# 必須の環境変数:
#   DEPLOY_HOST    例: sv1234.xserver.jp
#   DEPLOY_USER    例: xs123456（サーバーアカウント）
#   DEPLOY_PATH    サイトのルート。DEPLOY_LAYOUT=flat のときはドキュメントルート
#                  例: /home/xs123456/click.interminds.work/public_html
# 任意:
#   DEPLOY_PORT    SSH ポート（既定 22。XSERVER は 10022）
#   DEPLOY_KEY     秘密鍵のパス
#   DEPLOY_LAYOUT  flat（既定）: DEPLOY_PATH 直下に公開ファイル、app/ と vendor/ も同じ階層（.htaccess で保護）
#                  split: DEPLOY_PATH/public をドキュメントルートに設定できる場合。app/ と vendor/ は公開外
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
DRY_RUN="${DRY_RUN:-0}"

need() { [ -n "${!1:-}" ] || { echo "環境変数 $1 を設定してください（ヘッダのコメント参照）" >&2; exit 2; }; }

setup_ssh() {
  need DEPLOY_HOST; need DEPLOY_USER; need DEPLOY_PATH
  LAYOUT="${DEPLOY_LAYOUT:-flat}"
  case "$LAYOUT" in flat|split) ;; *) echo "DEPLOY_LAYOUT は flat か split" >&2; exit 2;; esac
  SSH_OPTS=(-p "${DEPLOY_PORT:-22}" -o StrictHostKeyChecking=accept-new)
  [ -n "${DEPLOY_KEY:-}" ] && SSH_OPTS+=(-i "$DEPLOY_KEY")
  REMOTE="$DEPLOY_USER@$DEPLOY_HOST"
  RSH="ssh ${SSH_OPTS[*]}"
  if [ "$LAYOUT" = flat ]; then
    PUBLIC_DIR="$DEPLOY_PATH"
  else
    PUBLIC_DIR="$DEPLOY_PATH/public"
  fi
  APP_DIR="$DEPLOY_PATH/app"
  VENDOR_DIR="$DEPLOY_PATH/vendor"
  [ "$LAYOUT" = flat ] && VENDOR_DIR="$DEPLOY_PATH/vendor"
}

remote() { ssh "${SSH_OPTS[@]}" "$REMOTE" "$@"; }

do_build() { "$ROOT/deploy/build.sh"; }

do_upload() {
  setup_ssh
  [ -d "$DIST/public" ] || { echo "dist/ がありません。先に build を実行してください" >&2; exit 1; }
  local n=(); [ "$DRY_RUN" = 1 ] && n=(-n) && echo "(DRY RUN: 送信せず内容のみ表示)"

  echo "→ $REMOTE:$DEPLOY_PATH ($LAYOUT)"
  if [ "$DRY_RUN" != 1 ]; then
    remote "mkdir -p '$PUBLIC_DIR' '$APP_DIR/var' '$VENDOR_DIR' && chmod 775 '$APP_DIR/var'"
  fi
  # 公開ファイル: 既存の他ファイル（.well-known 等）を消さないよう --delete は付けない
  rsync -az "${n[@]}" -e "$RSH" "$DIST/public/" "$REMOTE:$PUBLIC_DIR/"
  # app: サーバー側の config.php と var/（レート制限・ロック状態）は保持する
  rsync -az --delete "${n[@]}" -e "$RSH" --exclude='/config.php' --exclude='/var/' "$DIST/app/" "$REMOTE:$APP_DIR/"
  rsync -az --delete "${n[@]}" -e "$RSH" "$DIST/vendor/" "$REMOTE:$VENDOR_DIR/"
  echo "done. 次は: deploy/verify.sh https://click.interminds.work"
}

do_init_config() {
  setup_ssh
  local tmp; tmp="$(mktemp)"; trap 'rm -f "$tmp"' EXIT; chmod 600 "$tmp"
  local pw pw2 key="${ANTHROPIC_API_KEY:-}"
  read -r -s -p "ログインパスワード: " pw; echo
  read -r -s -p "もう一度: " pw2; echo
  [ "$pw" = "$pw2" ] && [ -n "$pw" ] || { echo "パスワードが一致しないか空です" >&2; exit 1; }
  if [ -z "$key" ]; then
    read -r -s -p "Anthropic API キー（空欄ならモックのみで運用）: " key; echo
  fi
  TF_PASSWORD="$pw" TF_API_KEY="$key" php "$ROOT/deploy/make-config.php" > "$tmp"
  php -l "$tmp" >/dev/null
  remote "mkdir -p '$APP_DIR'"
  scp -q -P "${DEPLOY_PORT:-22}" ${DEPLOY_KEY:+-i "$DEPLOY_KEY"} "$tmp" "$REMOTE:$APP_DIR/config.php"
  remote "chmod 600 '$APP_DIR/config.php'"
  [ -n "$key" ] && echo "config.php を作成しました（API キー設定済み）。" || echo "config.php を作成しました（API キー未設定・モック有効）。"
}

case "${1:-}" in
  all)         do_build; do_upload;;
  build)       do_build;;
  upload)      do_upload;;
  init-config) do_init_config;;
  *) sed -n '2,24p' "$0"; exit 1;;
esac
