import base64
import json

DEFAULT_MODEL = "claude-opus-5-5"
DEFAULT_EFFORT = "medium"
MAX_TOKENS = 16000
FALLBACK_BETA = "server-side-fallback-2026-07-01"

SYSTEM_PROMPT_HEAD = """\
あなたは法人営業のターゲティング専門家です。入力された商品・サービスの資料を読み、DataPark のマーケティングタグ（下記カタログ）から、営業先企業を絞り込むための最適なタグの組み合わせを選びます。

# タグの性質
- すべてのタグは、企業のホームページ・ニュース・公的データ等に基づく「該当フラグ」です。購入意欲や予算の有無を直接示すものではありません。reason には「〜の記載がある」「〜を募集している」のように、タグが実際に示す事実の範囲で根拠を書いてください。
- 分野タグ: その企業が扱う・提供する商品/サービス領域。「取扱品目」に相当し、商品を扱う企業（代理店・販売店・同業）や特定領域の事業者を探すときの主軸になります。
- 活動タグ: 展示会出展・補助金・採用・設立年・売上規模・ツール利用などの行動/属性。商品を必要としそうな状況（成長・投資・採用・設備導入など）を推定する材料です。
- インテントタグ: IT・ものづくり領域に限った関心シグナル。付与企業が計約1万社と少ないため、商品が明確に該当する場合のみ使います。
- 新しい動きタグ: 過去2年は無く直近2ヶ月で新規に出現した動き。営業タイミングの根拠です。母集団が小さいので、must ではなく should で高い重みを付ける使い方が基本です。
- トレンドタグ: DX・AI・IoT などのトレンド導入/提供企業。

# 選び方
1. まず商品の買い手（sell_mode）を判断します。使う企業に売る(sell_to_users)、扱う企業（代理店・販売店）に売る(sell_to_resellers)、両方(both)。
2. must は AND 条件です。母集団を絞る最重要の 0〜3 個に限ります（AND を増やすと該当企業が急減します）。
3. should は加点条件（重み1〜5）です。5〜12 個を目安に、適合度の根拠になるものを選びます。
4. exclude は除外条件です。同種の商品を提供する競合企業や、明らかに不適合な企業群に限ります。
5. 社数の小さいタグ（数百社以下）を must に入れてはいけません。
6. カタログに無いタグ番号を作ってはいけません。確信が持てないタグは入れず、無理に数を埋めないでください。
7. 資料から読み取れない事項を断定しないでください。不確かな点は caveats に書きます。
8. すべて日本語で出力します。

# タグカタログ
"""

USER_INSTRUCTION = "上記の資料の商品について、営業先を絞り込むタグを選定してください。"

_STR = {"type": "string"}


def _obj(properties):
    return {
        "type": "object",
        "properties": properties,
        "required": list(properties),
        "additionalProperties": False,
    }


def _str_list(desc):
    return {"type": "array", "items": _STR, "description": desc}


SELECTION_ITEM = _obj({"tag_id": {"type": "string", "description": "カタログのタグ番号"}, "reason": _STR})
SHOULD_ITEM = _obj(
    {
        "tag_id": {"type": "string", "description": "カタログのタグ番号"},
        "weight": {"type": "integer", "description": "適合度の重み。1（弱い）〜5（強い）"},
        "reason": _STR,
    }
)

OUTPUT_SCHEMA = _obj(
    {
        "product": _obj(
            {
                "name": {"type": "string", "description": "商品・サービス名"},
                "summary": {"type": "string", "description": "何を・誰向けに・どんな価値で、を2〜3文で"},
                "category": {"type": "string", "description": "商品カテゴリ"},
                "buyer_problems": _str_list("買い手が抱える課題"),
            }
        ),
        "target_hypothesis": _obj(
            {
                "sell_mode": {
                    "type": "string",
                    "enum": ["sell_to_users", "sell_to_resellers", "both"],
                    "description": "使う企業に売る / 扱う企業に売る / 両方",
                },
                "industries": _str_list("ターゲット業種"),
                "job_titles": _str_list("ターゲット職種・決裁者"),
                "company_size": {"type": "string", "description": "想定する企業規模（従業員・売上）"},
                "handled_items": _str_list("ターゲット企業が扱っていそうな取扱品目"),
                "keywords": _str_list("営業リスト検索で使うキーワード"),
            }
        ),
        "tag_selection": _obj(
            {
                "must": {"type": "array", "items": SELECTION_ITEM, "description": "AND条件（0〜3個）"},
                "should": {"type": "array", "items": SHOULD_ITEM, "description": "加点条件（重み付き）"},
                "exclude": {"type": "array", "items": SELECTION_ITEM, "description": "除外条件"},
            }
        ),
        "strategy_notes": {"type": "string", "description": "このタグ構成にした狙いと、使い分けの考え方"},
        "caveats": _str_list("不確かな点・資料から読み取れなかった点・注意点"),
    }
)


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
