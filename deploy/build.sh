#!/usr/bin/env bash
# dist/ にデプロイ用の一式（public / app / vendor）を作る。
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"

command -v composer >/dev/null || { echo "composer が必要です（https://getcomposer.org/）" >&2; exit 1; }

rm -rf "$DIST"
mkdir -p "$DIST/app/data" "$DIST/app/shared" "$DIST/app/examples"

cp -R "$ROOT/web/public" "$DIST/public"
cp -R "$ROOT/web/app/." "$DIST/app/"
rm -f "$DIST/app/config.php"   # ローカルの秘密情報は絶対に同梱しない
find "$DIST/app/var" -type f ! -name '.gitkeep' -delete

cp "$ROOT/data/tags.json" "$DIST/app/data/"
cp "$ROOT/shared/"* "$DIST/app/shared/"
cp "$ROOT/examples/sample_model_output.json" "$DIST/app/examples/"

cp "$ROOT/web/composer.json" "$ROOT/web/composer.lock" "$DIST/"
(cd "$DIST" && composer install --no-dev --optimize-autoloader --no-interaction --quiet)
rm -f "$DIST/composer.json" "$DIST/composer.lock"

# vendor を軽量化・安全化（git 履歴・テスト・サンプル・CI 設定は本番に不要）
find "$DIST/vendor" -type d \( -name .git -o -name .github -o -name tests -o -name Tests -o -name examples -o -name docs \) -prune -exec rm -rf {} + 2>/dev/null || true
find "$DIST/vendor" -type f \( -name '.gitattributes' -o -name '.gitignore' -o -name '*.md' \) -delete 2>/dev/null || true

cat > "$DIST/vendor/.htaccess" <<'HT'
<IfModule mod_authz_core.c>
  Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
  Order deny,allow
  Deny from all
</IfModule>
HT

echo "built: $DIST"
du -sh "$DIST" | awk '{print "size: "$1}'
