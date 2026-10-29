# tagfinder

商品のURL・PDF・テキストから、DataPark マーケティングタグ（`data/tags.json`、287種）の**営業先検索条件**を選定する CLI（プロトタイプ）。

```
商品URL/PDF/テキスト → Claude がタグを選定(must/should/exclude+理由) → 検証・件数見積もり・緩和 → 検索条件
```

## セットアップ

```bash
pip install -r requirements.txt     # Python 3.9 以上
export ANTHROPIC_API_KEY=sk-ant-...
```

## 使い方

```bash
# 商品ページ / PDFカタログ / テキストファイル（複数可・自動判別）
python -m tagfinder https://example.com/product catalog.pdf

# 商品説明を直接渡す + 前提を追加
python -m tagfinder --text "製造業向けの検査装置..." --hint "従業員300人以下の企業のみ"

# 結果を JSON で保存
python -m tagfinder catalog.pdf --out result.json

# API を呼ばずに送信内容を確認 / モデル出力の代わりにサンプルで動作確認
python -m tagfinder catalog.pdf --dry-run
python -m tagfinder --mock-response examples/sample_model_output.json
```

主なオプション: `--model`（既定 `claude-opus-5-5`）、`--effort`（既定 `medium`）、`--min-count`（既定 300）、`--no-fallback`。

## 出力の見方

| 項目 | 意味 |
|---|---|
| must | AND 条件。0〜3個。母集団を決める |
| should | 加点条件（重み1〜5）。スコア = 一致した重みの合計 |
| exclude | 除外条件（競合など） |
| 推定件数 | 現状は**モック**（タグ間の独立を仮定した概算）。実データとは乖離する |
| 緩和 | 推定件数が `--min-count` 未満のとき、社数が最小の must を should に降格 |

## 設計メモ

- タグは「HPやニュース等に該当の記載がある」フラグであり、購入意欲を直接示さない。理由文はその範囲で書かせている。
- タグカタログ（約2.3万文字）はシステムプロンプトに入れ、プロンプトキャッシュを有効化している。
- 出力は JSON スキーマで固定（構造化出力）。タグ番号はコード側でカタログと照合し、未知の番号は除外して警告する。
- 拒否（refusal）時のサーバー側フォールバックを既定で有効化している。通常エンドポイントを使うには `--no-fallback`。
- JavaScript 描画のページは本文が取れないことがある。その場合は PDF かテキストで入力する。

## 未実装・要確認

- **DataPark への接続**: `counts.py` の `DataParkBackend` が接続口（件数取得）。API/DB/CSV の提供形態が決まり次第実装。
- 実 API での通し実行は未検証（開発環境に API キーが無かったため）。テストは偽クライアントとモック出力で実施。
- NBS/DataPark 由来データの利用許諾・再販範囲。

## テスト

```bash
python -m unittest discover -s tests -v
```
