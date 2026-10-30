#!/usr/bin/env bash
# デプロイ後の動作確認。
#   使い方: deploy/verify.sh [BASE_URL]        （既定: https://click.interminds.work）
#   環境変数:
#     VERIFY_PASSWORD   指定するとログイン〜モック解析まで確認する
#     VERIFY_LIVE_TEXT  指定すると、その商品説明で実 API による解析まで確認する（API 利用料が発生）
#     VERIFY_CURL_OPTS  curl への追加オプション（例: -k）
set -u

BASE="${1:-https://click.interminds.work}"
BASE="${BASE%/}"
PASS=0; FAIL=0; SKIP=0
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
JAR="$TMP/cookies.txt"
# shellcheck disable=SC2206
COPTS=(-sS -m 120 ${VERIFY_CURL_OPTS:-})

ok()   { printf '  ok   %s\n' "$1"; PASS=$((PASS+1)); }
ng()   { printf '  FAIL %s\n' "$1"; FAIL=$((FAIL+1)); }
skip() { printf '  skip %s\n' "$1"; SKIP=$((SKIP+1)); }
code() { curl "${COPTS[@]}" -o "$TMP/body" -w '%{http_code}' "$@" 2>"$TMP/err" || echo 000; }
json() { python3 -c "import sys,json
d=json.load(open('$TMP/body'))
$1" 2>/dev/null; }

echo "== $BASE"

echo "[1] ヘルスチェック"
c=$(code "$BASE/health.php")
if [ "$c" = "200" ] && [ "$(json 'print(d.get("ok"))')" = "True" ]; then
  ok "health.php 200 / 必須チェックすべて OK"
else
  ng "health.php HTTP $c  $(head -c 300 "$TMP/err")"
  json 'print({k:v for k,v in d["checks"].items()})' || head -c 400 "$TMP/body"
fi
json 'c=d["checks"]; print("     php=%s tags=%s password_set=%s api_key_set=%s mock=%s" % (c["php_version"],c["tags_loaded"],c["password_set"],c["api_key_set"],c["mock_enabled"]))'
API_KEY_SET=$(json 'print(d["checks"]["api_key_set"])')
MOCK_ON=$(json 'print(d["checks"]["mock_enabled"])')

echo "[2] 画面"
c=$(code "$BASE/")
if [ "$c" = "200" ] && grep -q '営業先タグ選定' "$TMP/body"; then ok "トップページ 200（ログイン画面）"; else ng "トップページ HTTP $c"; fi
c=$(code "$BASE/assets/app.js"); [ "$c" = "200" ] && ok "assets/app.js 200" || ng "assets/app.js HTTP $c"
c=$(code "$BASE/assets/style.css"); [ "$c" = "200" ] && ok "assets/style.css 200" || ng "assets/style.css HTTP $c"

echo "[3] HTTPS"
case "$BASE" in
  https://*)
    c=$(curl "${COPTS[@]}" -o /dev/null -w '%{http_code} %{redirect_url}' "http://${BASE#https://}/" 2>/dev/null || echo "000 ")
    case "$c" in 30[1278]\ https://*) ok "HTTP → HTTPS にリダイレクト ($c)";; *) ng "HTTP から HTTPS へリダイレクトされない ($c)";; esac;;
  *) skip "HTTPS 以外の URL";;
esac

echo "[4] 非公開ファイルが見えないこと"
for p in app/config.php app/config.sample.php app/data/tags.json app/bootstrap.php app/lib/Selector.php app/shared/system_prompt.txt vendor/autoload.php app/var/analysis.json; do
  c=$(code "$BASE/$p")
  if [ "$c" = "200" ] || grep -q '<?php' "$TMP/body" 2>/dev/null; then ng "/$p が公開されている (HTTP $c)"; else ok "/$p は非公開 (HTTP $c)"; fi
done

echo "[5] 未ログインで API が使えないこと"
c=$(code -X POST "$BASE/api.php?action=analyze")
[ "$c" = "401" ] && ok "未ログインの POST は 401" || ng "未ログインの POST が HTTP $c"
c=$(code "$BASE/api.php"); [ "$c" = "405" ] && ok "GET は 405" || ng "GET が HTTP $c"

echo "[6] ログイン〜解析"
if [ -z "${VERIFY_PASSWORD:-}" ]; then
  skip "VERIFY_PASSWORD 未指定のためログイン確認を省略"
else
  curl "${COPTS[@]}" -c "$JAR" -o /dev/null "$BASE/" 2>/dev/null
  c=$(code -b "$JAR" -c "$JAR" --data-urlencode "password=__wrong__" "$BASE/")
  if grep -q 'パスワードが違います' "$TMP/body"; then ok "誤ったパスワードは拒否される"; else ng "誤パスワードの応答が想定と違う (HTTP $c)"; fi

  c=$(code -b "$JAR" -c "$JAR" --data-urlencode "password=$VERIFY_PASSWORD" "$BASE/")
  if [ "$c" != "303" ]; then ng "ログインに失敗 (HTTP $c)"; else
    ok "ログイン成功 (303)"
    c=$(code -b "$JAR" -c "$JAR" "$BASE/")
    CSRF=$(grep -o 'name="csrf-token" content="[^"]*"' "$TMP/body" | sed 's/.*content="//;s/"$//')
    if [ "$c" = "200" ] && [ -n "$CSRF" ] && grep -q 'analyze-form' "$TMP/body"; then ok "ログイン後の画面（解析フォーム）が表示される"; else ng "ログイン後の画面が想定と違う (HTTP $c)"; fi

    c=$(code -b "$JAR" -X POST -H "X-CSRF-Token: wrong" "$BASE/api.php?action=analyze")
    [ "$c" = "403" ] && ok "CSRF トークン不一致は 403" || ng "CSRF 不一致が HTTP $c"

    if [ "$MOCK_ON" = "True" ]; then
      c=$(code -b "$JAR" -X POST -H "X-CSRF-Token: $CSRF" -F mock=1 "$BASE/api.php?action=analyze")
      if [ "$c" = "200" ] && [ "$(json 'r=d["result"]; print(d["ok"] and r["meta"]["mock"] and isinstance(r["query"]["estimate"]["count"],int) and len(r["query"]["must"])>0)')" = "True" ]; then
        ok "モック解析 200（検索条件と件数見積もりが返る）"
      else ng "モック解析が失敗 (HTTP $c) $(head -c 200 "$TMP/body")"; fi
    else
      skip "モックは無効（allow_mock=false）"
    fi

    if [ -n "${VERIFY_LIVE_TEXT:-}" ]; then
      if [ "$API_KEY_SET" != "True" ]; then ng "実 API 解析: API キーが未設定"; else
        c=$(code -b "$JAR" -X POST -H "X-CSRF-Token: $CSRF" --data-urlencode "text=$VERIFY_LIVE_TEXT" "$BASE/api.php?action=analyze")
        if [ "$c" = "200" ] && [ "$(json 'r=d["result"]; print(d["ok"] and not r["meta"]["mock"] and (len(r["query"]["must"])+len(r["query"]["should"]))>0)')" = "True" ]; then
          ok "実 API 解析 200"
          json 'r=d["result"]; q=r["query"]; print("     model=%s must=%d should=%d exclude=%d estimate=%s tokens(in/out)=%s/%s" % (r["meta"]["model"],len(q["must"]),len(q["should"]),len(q["exclude"]),q["estimate"]["count"],r["usage"]["input_tokens"],r["usage"]["output_tokens"]))'
        else ng "実 API 解析が失敗 (HTTP $c) $(head -c 300 "$TMP/body")"; fi
      fi
    else
      skip "実 API 解析は VERIFY_LIVE_TEXT 指定時のみ"
    fi
  fi
fi

echo
echo "結果: $PASS 件 OK / $FAIL 件 NG / $SKIP 件 スキップ"
[ "$FAIL" -eq 0 ]
