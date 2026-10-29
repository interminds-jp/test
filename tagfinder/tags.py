import json
from dataclasses import dataclass
from pathlib import Path

DEFAULT_TAGS_PATH = Path(__file__).resolve().parent.parent / "data" / "tags.json"

KIND_ORDER = ["field", "activity", "intent", "new_move", "trend"]

KIND_LABELS = {
    "field": "分野タグ",
    "activity": "活動タグ",
    "intent": "インテントタグ",
    "new_move": "新しい動きタグ",
    "trend": "トレンドタグ",
}

KIND_HINTS = {
    "field": "その企業が扱う・提供する商品/サービス領域（取扱品目に相当）",
    "activity": "展示会出展・補助金・採用・設立年・売上規模・ツール利用などの行動/属性",
    "intent": "IT・ものづくり領域に限った関心シグナル（付与企業は計約1万社と少ない）",
    "new_move": "過去2年は無く直近2ヶ月で新規に出現した動き（営業タイミング）",
    "trend": "DX・AI・IoT などのトレンド導入/提供企業",
}


@dataclass(frozen=True)
class Tag:
    id: str
    kind: str
    category: str
    name: str
    count: int
    desc: str


class TagMaster:
    def __init__(self, tags, meta):
        self.tags = list(tags)
        self.meta = meta
        self.by_id = {t.id: t for t in self.tags}

    @classmethod
    def load(cls, path=None):
        path = Path(path) if path else DEFAULT_TAGS_PATH
        with open(path, encoding="utf-8") as f:
            data = json.load(f)
        tags = [
            Tag(
                id=str(t["id"]),
                kind=t["kind"],
                category=t["category"],
                name=t["name"],
                count=int(t["count"]),
                desc=t["desc"],
            )
            for t in data["tags"]
        ]
        return cls(tags, data["meta"])

    @property
    def total_companies(self):
        return int(self.meta["tagged_companies"]["marketing_total"])

    @property
    def source_label(self):
        return self.meta.get("source", "")

    def resolve(self, raw_id):
        """モデル出力のタグ番号ゆれ（前後空白・小文字・数値型）を吸収して Tag を返す。"""
        key = str(raw_id).strip().upper()
        return self.by_id.get(key)

    def catalog_text(self):
        lines = []
        for kind in KIND_ORDER:
            tags = [t for t in self.tags if t.kind == kind]
            lines.append(f"## {KIND_LABELS[kind]}（{kind}・{len(tags)}種）: {KIND_HINTS[kind]}")
            lines.append("番号 | カテゴリー | タグ名 | 社数 | 説明")
            for t in tags:
                lines.append(f"{t.id} | {t.category} | {t.name} | {t.count}社 | {t.desc}")
            lines.append("")
        return "\n".join(lines)
