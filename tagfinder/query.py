from dataclasses import dataclass, replace

DEMOTED_WEIGHT = 4
MAX_MUST_RECOMMENDED = 3
MAX_RELAX_STEPS = 10
SCORING_RULE = "スコア = Σ（一致した should タグの weight）。must を満たし exclude に該当しない企業を、スコア降順で並べる。"


@dataclass(frozen=True)
class Selection:
    tag_id: str
    reason: str
    weight: int = 0


def _clamp_weight(value):
    try:
        return max(1, min(5, int(value)))
    except (TypeError, ValueError):
        return 3


def _resolve_list(items, master, label, warnings, with_weight=False):
    out, seen = [], set()
    for item in items or []:
        raw_id = item.get("tag_id")
        tag = master.resolve(raw_id)
        if tag is None:
            warnings.append(f"{label}: カタログに存在しないタグ番号 {raw_id!r} を除外しました。")
            continue
        if tag.id in seen:
            warnings.append(f"{label}: タグ {tag.id} が重複していたため1件にまとめました。")
            continue
        seen.add(tag.id)
        weight = _clamp_weight(item.get("weight")) if with_weight else 0
        out.append(Selection(tag.id, item.get("reason", ""), weight))
    return out


def normalize(raw_selection, master, warnings):
    """モデル出力のタグ選択を検証し、must > exclude > should の優先で矛盾を解消する。"""
    must = _resolve_list(raw_selection.get("must"), master, "must", warnings)
    should = _resolve_list(raw_selection.get("should"), master, "should", warnings, with_weight=True)
    exclude = _resolve_list(raw_selection.get("exclude"), master, "exclude", warnings)

    must_ids = {s.tag_id for s in must}
    exclude_ids = {s.tag_id for s in exclude}

    conflict = exclude_ids & must_ids
    if conflict:
        warnings.append(f"must と exclude の両方にあるタグ {sorted(conflict)} は must を優先し exclude から外しました。")
        exclude = [s for s in exclude if s.tag_id not in conflict]
        exclude_ids -= conflict

    dropped = [s.tag_id for s in should if s.tag_id in must_ids or s.tag_id in exclude_ids]
    if dropped:
        warnings.append(f"must/exclude と重複する should タグ {dropped} を should から外しました。")
        should = [s for s in should if s.tag_id not in must_ids | exclude_ids]

    if len(must) > MAX_MUST_RECOMMENDED:
        warnings.append(f"must が {len(must)} 個あります（推奨は {MAX_MUST_RECOMMENDED} 個以下）。AND 条件が多いと該当企業が急減します。")
    return must, should, exclude


def relax_until(master, backend, must, should, exclude, min_count):
    """推定件数が min_count 未満の間、最も社数の少ない must を should に降格して条件を緩める。"""
    must, should = list(must), list(should)
    steps = []
    result = backend.count(master, must, should, exclude)
    while result.estimate is not None and result.estimate < min_count and must and len(steps) < MAX_RELAX_STEPS:
        victim = min(must, key=lambda s: master.by_id[s.tag_id].count)
        must.remove(victim)
        should.append(
            replace(victim, weight=DEMOTED_WEIGHT, reason=f"{victim.reason}（件数不足のため must から降格）")
        )
        before = result.estimate
        result = backend.count(master, must, should, exclude)
        steps.append(
            {
                "demoted_tag_id": victim.tag_id,
                "demoted_tag_name": master.by_id[victim.tag_id].name,
                "estimate_before": before,
                "estimate_after": result.estimate,
            }
        )
    return must, should, steps, result


def _describe(master, sel, with_weight=False):
    t = master.by_id[sel.tag_id]
    d = {"tag_id": t.id, "name": t.name, "kind": t.kind, "category": t.category, "company_count": t.count, "reason": sel.reason}
    if with_weight:
        d["weight"] = sel.weight
    return d


def build_query(raw, master, backend, min_count):
    """モデル出力 raw から、検証済みの検索条件と件数見積もりを組み立てる。"""
    warnings = []
    must, should, exclude = normalize(raw.get("tag_selection", {}), master, warnings)
    if not (must or should):
        warnings.append("有効な must/should タグが1つもありません。商品資料の内容を確認してください。")

    must, should, steps, result = relax_until(master, backend, must, should, exclude, min_count)
    if result.estimate is not None and result.estimate < min_count:
        warnings.append(f"条件を緩めても推定件数が {min_count:,} 件に届きませんでした（{result.estimate:,} 件）。")

    return {
        "must": [_describe(master, s) for s in must],
        "should": sorted((_describe(master, s, True) for s in should), key=lambda d: -d["weight"]),
        "exclude": [_describe(master, s) for s in exclude],
        "scoring_rule": SCORING_RULE,
        "min_count": min_count,
        "estimate": {"count": result.estimate, "method": result.method},
        "relaxation_steps": steps,
        "warnings": warnings,
    }
