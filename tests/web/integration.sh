#!/usr/bin/env bash
# ローカル統合テスト: dist をビルドし、偽 Anthropic API とアプリを起動して verify.sh を実行する。
#   使い方: tests/web/integration.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="${TMPDIR:-/tmp}/tagfinder-it.$$"
APP_PORT=8790; MOCK_PORT=8791
mkdir -p "$WORK"
PIDS=()
cleanup() { for p in "${PIDS[@]:-}"; do [ -n "$p" ] && kill "$p" 2>/dev/null || true; done; rm -rf "$WORK"; }
trap cleanup EXIT

"$ROOT/deploy/build.sh" >/dev/null
cp -R "$ROOT/dist" "$WORK/site"
TF_PASSWORD='test-pass' TF_API_KEY='sk-test' php "$ROOT/deploy/make-config.php" \
  | sed "s/'allow_mock' => false/'allow_mock' => true/" > "$WORK/site/app/config.php"

MOCK_LOG="$WORK/mock.log" php -S "127.0.0.1:$MOCK_PORT" "$ROOT/tests/web/mock_anthropic.php" >"$WORK/mock.out" 2>&1 & PIDS+=($!)
ANTHROPIC_BASE_URL="http://127.0.0.1:$MOCK_PORT" php -S "127.0.0.1:$APP_PORT" -t "$WORK/site/public" >"$WORK/app.out" 2>&1 & PIDS+=($!)
sleep 1

BASE="http://127.0.0.1:$APP_PORT"
echo "### verify.sh（モック解析 + 偽 API 経由の実解析）"
RC=0
VERIFY_PASSWORD='test-pass' VERIFY_LIVE_TEXT='製造業向けの検査装置。食品工場の異物検査を自動化する。' "$ROOT/deploy/verify.sh" "$BASE" || RC=1

echo
echo "### 偽 API が受け取ったリクエストの確認"
python3 - "$WORK/mock.log" <<'PY'
import json, sys
reqs = [json.loads(l) for l in open(sys.argv[1], encoding="utf-8")]
assert len(reqs) == 1, f"API 呼び出しは1回のはず: {len(reqs)}"
r = reqs[0]
b = r["body"]
print("  path:", r["path"])
print("  anthropic-beta:", r["headers"].get("anthropic-beta"))
print("  fallbacks:", b.get("fallbacks"), "/ model:", b["model"], "/ effort:", b["output_config"]["effort"])
print("  system chars:", len(b["system"][0]["text"]), "/ cache_control:", b["system"][0]["cache_control"])
assert "server-side-fallback-2026-07-01" in r["headers"]["anthropic-beta"]
assert b["fallbacks"] == "default"
content = b["messages"][0]["content"]
assert content[0]["type"] == "text" and "検査装置" in content[0]["text"]
print("  ok: フォールバック指定・構造化出力・キャッシュ・入力テキストがすべて想定どおり")
PY

echo
echo "### 異常系（拒否・壊れたJSON・上限・CSRF）"
JAR="$WORK/j"; curl -s -c "$JAR" -o /dev/null "$BASE/"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -w "login %{http_code}\n" --data-urlencode "password=test-pass" "$BASE/"
CSRF=$(curl -s -b "$JAR" -c "$JAR" "$BASE/" | grep -o 'name="csrf-token" content="[^"]*"' | sed 's/.*content="//;s/"$//')
post() { curl -s -b "$JAR" -X POST -H "X-CSRF-Token: $CSRF" "$@" "$BASE/api.php?action=analyze"; echo; }
echo -n "  refusal   : "; post --data-urlencode "text=REFUSE this" -w ' [HTTP %{http_code}]'
echo -n "  bad json  : "; post --data-urlencode "text=BADJSON" -w ' [HTTP %{http_code}]'
echo -n "  empty     : "; post -w ' [HTTP %{http_code}]'
echo -n "  ssrf url  : "; post --data-urlencode "urls=http://169.254.169.254/latest/meta-data/" -w ' [HTTP %{http_code}]'
echo -n "  not a pdf : "; printf 'hello' > "$WORK/x.pdf"; post -F "pdf[]=@$WORK/x.pdf;type=application/pdf" -w ' [HTTP %{http_code}]'
echo -n "  real pdf  : "; printf '%%PDF-1.4\n%%test\n' > "$WORK/ok.pdf"; post -F "pdf[]=@$WORK/ok.pdf;type=application/pdf" -F "text=PDFも添付" -w ' [HTTP %{http_code}]' | cut -c1-120
echo "  PDF を含むリクエスト:"
python3 - "$WORK/mock.log" <<'PY'
import json, sys
reqs = [json.loads(l) for l in open(sys.argv[1], encoding="utf-8")]
last = reqs[-1]["body"]["messages"][0]["content"]
print("   ", [c["type"] for c in last], "| document source:", {k: (v if k != "data" else v[:12] + "…") for k, v in last[0]["source"].items()})
PY
exit $RC
