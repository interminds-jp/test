import base64
import json
from pathlib import Path

DEFAULT_MODEL = "claude-opus-5-5"
DEFAULT_EFFORT = "medium"
MAX_TOKENS = 16000
FALLBACK_BETA = "server-side-fallback-2026-07-01"

# プロンプトと出力スキーマは Web 版（PHP）と共有する。編集は shared/ 配下のファイルで行う。
SHARED_DIR = Path(__file__).resolve().parent.parent / "shared"
SYSTEM_PROMPT_HEAD = (SHARED_DIR / "system_prompt.txt").read_text(encoding="utf-8")
USER_INSTRUCTION = (SHARED_DIR / "user_instruction.txt").read_text(encoding="utf-8")
OUTPUT_SCHEMA = json.loads((SHARED_DIR / "output_schema.json").read_text(encoding="utf-8"))


class SelectionError(Exception):
    pass


def build_system(master):
    return [
        {
            "type": "text",
            "text": SYSTEM_PROMPT_HEAD + master.catalog_text(),
            "cache_control": {"type": "ephemeral"},
        }
    ]


def build_user_content(sources, hint=""):
    """PDF はドキュメントブロックとして先頭に、テキストは出典ラベル付きで並べる。"""
    content = []
    for s in sources:
        if s.kind == "pdf":
            content.append(
                {
                    "type": "document",
                    "title": s.label,
                    "source": {
                        "type": "base64",
                        "media_type": "application/pdf",
                        "data": base64.standard_b64encode(s.data).decode("ascii"),
                    },
                }
            )
    for s in sources:
        if s.kind == "text":
            content.append({"type": "text", "text": f"<source label=\"{s.label}\">\n{s.data}\n</source>"})
    instruction = USER_INSTRUCTION
    if hint:
        instruction += f"\n\n追加の指示・前提:\n{hint}"
    content.append({"type": "text", "text": instruction})
    return content


def build_request(master, sources, hint, model, effort):
    return {
        "model": model,
        "max_tokens": MAX_TOKENS,
        "system": build_system(master),
        "messages": [{"role": "user", "content": build_user_content(sources, hint)}],
        "output_config": {
            "effort": effort,
            "format": {"type": "json_schema", "schema": OUTPUT_SCHEMA},
        },
    }


def parse_response(response):
    """stop_reason を確認し、構造化出力の JSON を dict にして返す。"""
    if response.stop_reason == "refusal":
        details = getattr(response, "stop_details", None)
        category = getattr(details, "category", None)
        raise SelectionError(f"モデルが応答を拒否しました（category={category}）。")
    if response.stop_reason == "max_tokens":
        raise SelectionError("出力が max_tokens に達して途中で切れました。")
    text = next((b.text for b in response.content if b.type == "text"), None)
    if text is None:
        raise SelectionError("応答にテキストブロックがありません。")
    try:
        return json.loads(text)
    except json.JSONDecodeError as e:
        raise SelectionError(f"応答が JSON として解釈できません: {e}") from e


def call_model(client, request, fallback=True):
    if fallback:
        return client.beta.messages.create(betas=[FALLBACK_BETA], fallbacks="default", **request)
    return client.messages.create(**request)


def usage_summary(response):
    u = response.usage
    return {
        "input_tokens": getattr(u, "input_tokens", None),
        "output_tokens": getattr(u, "output_tokens", None),
        "cache_creation_input_tokens": getattr(u, "cache_creation_input_tokens", None),
        "cache_read_input_tokens": getattr(u, "cache_read_input_tokens", None),
    }
