import argparse
import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path

from .counts import MockBackend
from .query import build_query
from .report import render_report
from .selector import (
    DEFAULT_EFFORT,
    DEFAULT_MODEL,
    SelectionError,
    build_request,
    call_model,
    parse_response,
    usage_summary,
)
from .sources import SourceError, inline_text_source, load_source
from .tags import TagMaster

PRODUCT_DEFAULTS = {"name": "(不明)", "summary": "", "category": "", "buyer_problems": []}
HYPOTHESIS_DEFAULTS = {
    "sell_mode": "sell_to_users",
    "industries": [],
    "job_titles": [],
    "company_size": "",
    "handled_items": [],
    "keywords": [],
}


def parse_args(argv):
    p = argparse.ArgumentParser(
        prog="tagfinder",
        description="商品のURL/PDF/テキストから、DataPark マーケティングタグの営業先検索条件を選定する。",
    )
    p.add_argument("sources", nargs="*", help="商品ページURL、PDFファイル、テキスト/HTMLファイル（複数可・自動判別）")
    p.add_argument("--text", help="商品説明を直接渡す")
    p.add_argument("--hint", default="", help="追加の前提（例: 対象は従業員300人以下の製造業のみ）")
    p.add_argument("--model", default=DEFAULT_MODEL, help=f"使用モデル（既定: {DEFAULT_MODEL}）")
    p.add_argument("--effort", default=DEFAULT_EFFORT, choices=["low", "medium", "high", "xhigh", "max"], help=f"思考の深さ（既定: {DEFAULT_EFFORT}）")
    p.add_argument("--min-count", type=int, default=300, help="目標とする最小の該当企業数。下回ると must を should に降格して緩和する（既定: 300）")
    p.add_argument("--tags", help="タグマスタ JSON のパス（既定: data/tags.json）")
    p.add_argument("--out", help="結果 JSON の保存先")
    p.add_argument("--json", action="store_true", help="人間向けレポートの代わりに JSON を標準出力へ出す")
    p.add_argument("--no-fallback", action="store_true", help="拒否時のサーバー側フォールバックを使わない（通常エンドポイントで呼ぶ）")
    p.add_argument("--dry-run", action="store_true", help="API を呼ばず、送信内容の概要だけ表示する")
    p.add_argument("--mock-response", help="API の代わりに、モデル出力を模した JSON ファイルを使う（オフライン検証用）")
    return p.parse_args(argv)


def has_credentials():
    if os.environ.get("ANTHROPIC_API_KEY") or os.environ.get("ANTHROPIC_AUTH_TOKEN"):
        return True
    return (Path.home() / ".config" / "anthropic").is_dir()


def with_defaults(raw):
    raw = dict(raw)
    raw["product"] = {**PRODUCT_DEFAULTS, **raw.get("product", {})}
    raw["target_hypothesis"] = {**HYPOTHESIS_DEFAULTS, **raw.get("target_hypothesis", {})}
    raw.setdefault("tag_selection", {})
    raw.setdefault("strategy_notes", "")
    raw.setdefault("caveats", [])
    return raw


def print_dry_run(request, sources):
    content = request["messages"][0]["content"]
    pdfs = [b for b in content if b["type"] == "document"]
    texts = [b["text"] for b in content if b["type"] == "text"]
    system_chars = sum(len(b["text"]) for b in request["system"])
    print(f"model={request['model']} effort={request['output_config']['effort']}")
    print(f"システムプロンプト: {system_chars:,} 文字（タグカタログ含む）")
    print(f"入力: PDF {len(pdfs)} 件 / テキスト {sum(len(t) for t in texts):,} 文字 / 出典 {len(sources)} 件")
    for s in sources:
        size = len(s.data)
        print(f"  - [{s.kind}] {s.label}（{size:,} {'bytes' if s.kind == 'pdf' else '文字'}）")
    print("--- 指示文 ---")
    print(texts[-1])


def run(args):
    master = TagMaster.load(args.tags)
    warnings = []
    sources = []
    for spec in args.sources:
        sources.append(load_source(spec, warnings))
    if args.text:
        sources.append(inline_text_source(args.text, warnings))
    if not sources and not args.mock_response:
        raise SystemExit("入力がありません。URL・PDF・テキストファイル、または --text を指定してください。")

    request = build_request(master, sources, args.hint, args.model, args.effort)
    usage = None

    if args.dry_run:
        print_dry_run(request, sources)
        for w in warnings:
            print(f"警告: {w}", file=sys.stderr)
        return None

    if args.mock_response:
        raw = json.loads(Path(args.mock_response).read_text(encoding="utf-8"))
    else:
        if not has_credentials():
            raise SystemExit("認証情報がありません。環境変数 ANTHROPIC_API_KEY を設定してください。")
        import anthropic

        client = anthropic.Anthropic()
        try:
            response = call_model(client, request, fallback=not args.no_fallback)
        except anthropic.AuthenticationError:
            raise SystemExit("認証に失敗しました。ANTHROPIC_API_KEY を確認してください。")
        except anthropic.PermissionDeniedError:
            raise SystemExit("この API キーには必要な権限がありません。")
        except anthropic.NotFoundError:
            raise SystemExit(f"モデル {args.model!r} が見つかりません。--model を確認してください。")
        except anthropic.RateLimitError as e:
            wait = e.response.headers.get("retry-after", "60")
            raise SystemExit(f"レート制限に達しました。{wait} 秒後に再試行してください。")
        except anthropic.BadRequestError as e:
            raise SystemExit(f"リクエストが不正です: {e.message}")
        except anthropic.APIStatusError as e:
            raise SystemExit(f"API エラー（{e.status_code}）: {e.message}")
        except anthropic.APIConnectionError:
            raise SystemExit("ネットワークエラーです。接続を確認してください。")
        raw = parse_response(response)
        usage = usage_summary(response)

    raw = with_defaults(raw)
    query = build_query(raw, master, MockBackend(), args.min_count)
    return {
        "meta": {
            "model": args.model if not args.mock_response else f"(mock) {args.mock_response}",
            "tag_master": master.source_label,
            "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
            "inputs": [s.label for s in sources],
            "warnings": warnings,
        },
        "product": raw["product"],
        "target_hypothesis": raw["target_hypothesis"],
        "query": query,
        "strategy_notes": raw["strategy_notes"],
        "caveats": raw["caveats"],
        "usage": usage,
    }


def main(argv=None):
    args = parse_args(argv)
    try:
        result = run(args)
    except (SourceError, SelectionError) as e:
        print(f"エラー: {e}", file=sys.stderr)
        return 1
    if result is None:
        return 0
    if args.out:
        Path(args.out).write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    if args.json:
        print(json.dumps(result, ensure_ascii=False, indent=2))
    else:
        print(render_report(result))
        if args.out:
            print(f"\n結果 JSON を保存しました: {args.out}")
    return 0
