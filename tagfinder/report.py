SELL_MODE_LABELS = {
    "sell_to_users": "商品を使う企業に売る",
    "sell_to_resellers": "商品を扱う企業（代理店・販売店）に売る",
    "both": "使う企業・扱う企業の両方",
}


def _join(items):
    return "、".join(items) if items else "（なし）"


def _tag_line(t):
    weight = f" 重み{t['weight']}" if "weight" in t else ""
    return f"  - [{t['tag_id']}] {t['name']}（{t['company_count']:,}社）{weight}\n      {t['reason']}"


def render_report(result):
    p = result["product"]
    h = result["target_hypothesis"]
    q = result["query"]
    est = q["estimate"]

    lines = [
        f"■ 商品: {p['name']}",
        f"  {p['summary']}",
        f"  カテゴリ: {p['category']}",
        f"  買い手の課題: {_join(p['buyer_problems'])}",
        "",
        f"■ ターゲット仮説（買い手: {SELL_MODE_LABELS.get(h['sell_mode'], h['sell_mode'])}）",
        f"  業種: {_join(h['industries'])}",
        f"  職種: {_join(h['job_titles'])}",
        f"  規模: {h['company_size'] or '（不明）'}",
        f"  取扱品目: {_join(h['handled_items'])}",
        f"  キーワード: {_join(h['keywords'])}",
        "",
        "■ 検索条件",
        "  [must: AND]" if q["must"] else "  [must: AND] （なし）",
    ]
    lines += [_tag_line(t) for t in q["must"]]
    lines.append("  [should: 加点]" if q["should"] else "  [should: 加点] （なし）")
    lines += [_tag_line(t) for t in q["should"]]
    lines.append("  [exclude: 除外]" if q["exclude"] else "  [exclude: 除外] （なし）")
    lines += [_tag_line(t) for t in q["exclude"]]
    lines += ["", f"  {q['scoring_rule']}", ""]

    if est["count"] is None:
        lines.append("■ 推定件数: 算出できません")
    else:
        lines.append(f"■ 推定件数: 約 {est['count']:,} 社（目標 {q['min_count']:,} 社以上）")
    lines.append(f"  方式: {est['method']}")
    for s in q["relaxation_steps"]:
        lines.append(
            f"  緩和: [{s['demoted_tag_id']}] {s['demoted_tag_name']} を must → should に降格"
            f"（{s['estimate_before']:,} → {s['estimate_after']:,} 社）"
        )

    if result["strategy_notes"]:
        lines += ["", "■ 選定の考え方", f"  {result['strategy_notes']}"]
    notes = list(result["caveats"]) + [f"（システム）{w}" for w in result["meta"]["warnings"] + q["warnings"]]
    if notes:
        lines += ["", "■ 注意"]
        lines += [f"  - {n}" for n in notes]
    return "\n".join(lines)
