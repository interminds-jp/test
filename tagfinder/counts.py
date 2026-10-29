from dataclasses import dataclass


@dataclass
class CountResult:
    estimate: "int | None"
    method: str


class CountBackend:
    """条件に合う企業数を返すバックエンドの共通インターフェース。

    must: AND 条件 / should: 加点（must が空のときだけ候補集合を決める OR 条件）/ exclude: 除外。
    各引数は tag_id 属性を持つ Selection のリスト。
    """

    def count(self, master, must, should, exclude):
        raise NotImplementedError


class MockBackend(CountBackend):
    """タグ間の独立を仮定した概算。実データの重なりとは乖離するため目安としてのみ使う。"""

    method = "概算（タグ間の独立を仮定したモック。実データとは乖離します）"

    def count(self, master, must, should, exclude):
        n = master.total_companies

        def p(sel):
            return master.by_id[sel.tag_id].count / n

        if must:
            base = 1.0
            for s in must:
                base *= p(s)
        elif should:
            none_matched = 1.0
            for s in should:
                none_matched *= 1.0 - p(s)
            base = 1.0 - none_matched
        else:
            return CountResult(None, self.method)

        for s in exclude:
            base *= 1.0 - p(s)
        return CountResult(round(n * base), self.method)


class DataParkBackend(CountBackend):
    """DataPark 本体への接続口（未実装）。

    実装時の要件:
      - must の AND、exclude の NOT で絞り込んだ該当企業数を返す。
      - must が空のときは should の OR を候補集合とする。
      - 件数のみの問い合わせ（企業情報を取得しない）ができること。
    """

    method = "DataPark 実データ"

    def count(self, master, must, should, exclude):
        raise NotImplementedError("DataPark への接続は未実装です（API/DB/CSV の提供形態が未確定）。")
