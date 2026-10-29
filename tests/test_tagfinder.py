import io
import json
import sys
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from tagfinder import cli  # noqa: E402
from tagfinder.counts import CountResult, MockBackend  # noqa: E402
from tagfinder.query import Selection, build_query, normalize, relax_until  # noqa: E402
from tagfinder.selector import (  # noqa: E402
    FALLBACK_BETA,
    OUTPUT_SCHEMA,
    SelectionError,
    build_request,
    call_model,
    parse_response,
)
from tagfinder.sources import Source, html_to_text  # noqa: E402
from tagfinder.tags import TagMaster  # noqa: E402

SAMPLE = ROOT / "examples" / "sample_model_output.json"


def fake_response(text=None, stop_reason="end_turn", category=None):
    content = [SimpleNamespace(type="text", text=text)] if text is not None else []
    return SimpleNamespace(
        stop_reason=stop_reason,
        stop_details=SimpleNamespace(category=category),
        content=content,
        usage=SimpleNamespace(input_tokens=1, output_tokens=2, cache_creation_input_tokens=0, cache_read_input_tokens=0),
    )


class TagMasterTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.master = TagMaster.load()

    def test_counts_match_headings(self):
        by_kind = {}
        for t in self.master.tags:
            by_kind[t.kind] = by_kind.get(t.kind, 0) + 1
        self.assertEqual(by_kind, {"activity": 89, "intent": 25, "new_move": 10, "trend": 21, "field": 142})
        self.assertEqual(len(self.master.tags), 287)
        self.assertEqual(len(self.master.by_id), 287)

    def test_catalog_contains_every_tag(self):
        text = self.master.catalog_text()
        for t in self.master.tags:
            self.assertIn(f"{t.id} | ", text)

    def test_resolve_normalizes_ids(self):
        self.assertEqual(self.master.resolve(" ita0a00 ").id, "ITA0A00")
        self.assertEqual(self.master.resolve(2209).id, "2209")
        self.assertIsNone(self.master.resolve("9999"))


class QueryTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.master = TagMaster.load()

    def test_unknown_and_duplicate_ids_are_reported(self):
        warnings = []
        must, should, exclude = normalize(
            {
                "must": [{"tag_id": "2209", "reason": "a"}, {"tag_id": "2209", "reason": "dup"}],
                "should": [{"tag_id": "9999", "weight": 3, "reason": "x"}, {"tag_id": "5209", "weight": 99, "reason": "y"}],
                "exclude": [],
            },
            self.master,
            warnings,
        )
        self.assertEqual([s.tag_id for s in must], ["2209"])
        self.assertEqual([(s.tag_id, s.weight) for s in should], [("5209", 5)])
        self.assertEqual(len(warnings), 2)

    def test_conflicts_resolve_must_over_exclude_over_should(self):
        warnings = []
        must, should, exclude = normalize(
            {
                "must": [{"tag_id": "2209", "reason": ""}],
                "should": [
                    {"tag_id": "2209", "weight": 2, "reason": ""},
                    {"tag_id": "1102", "weight": 2, "reason": ""},
                    {"tag_id": "3026", "weight": 2, "reason": ""},
                ],
                "exclude": [{"tag_id": "2209", "reason": ""}, {"tag_id": "1102", "reason": ""}],
            },
            self.master,
            warnings,
        )
        self.assertEqual([s.tag_id for s in must], ["2209"])
        self.assertEqual([s.tag_id for s in exclude], ["1102"])
        self.assertEqual([s.tag_id for s in should], ["3026"])

    def test_mock_estimate_uses_independence(self):
        backend = MockBackend()
        n = self.master.total_companies
        c = self.master.by_id["2209"].count
        res = backend.count(self.master, [Selection("2209", "")], [], [])
        self.assertEqual(res.estimate, c)
        res2 = backend.count(self.master, [Selection("2209", ""), Selection("3026", "")], [], [])
        self.assertEqual(res2.estimate, round(n * (c / n) * (self.master.by_id["3026"].count / n)))
        self.assertIsNone(backend.count(self.master, [], [], []).estimate)

    def test_relax_demotes_smallest_must_until_target(self):
        must = [Selection("2209", "big"), Selection("5209", "small"), Selection("3022", "huge")]
        _, should, steps, result = relax_until(self.master, MockBackend(), must, [], [], min_count=300)
        self.assertGreaterEqual(result.estimate, 300)
        self.assertTrue(steps)
        self.assertEqual(steps[0]["demoted_tag_id"], "5209")
        self.assertIn("5209", [s.tag_id for s in should])

    def test_relax_gives_up_with_warning(self):
        class ZeroBackend:
            def count(self, master, must, should, exclude):
                return CountResult(0, "zero")

        raw = {"tag_selection": {"must": [{"tag_id": "2209", "reason": ""}], "should": [], "exclude": []}}
        q = build_query(raw, self.master, ZeroBackend(), 100)
        self.assertEqual(q["must"], [])
        self.assertTrue(any("届きませんでした" in w for w in q["warnings"]))

    def test_build_query_sorts_should_by_weight(self):
        raw = json.loads(SAMPLE.read_text(encoding="utf-8"))
        q = build_query(raw, self.master, MockBackend(), 300)
        weights = [t["weight"] for t in q["should"]]
        self.assertEqual(weights, sorted(weights, reverse=True))
        self.assertEqual(q["relaxation_steps"], [])


class SelectorTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.master = TagMaster.load()

    def test_schema_objects_are_strict(self):
        def walk(node):
            if isinstance(node, dict):
                if node.get("type") == "object":
                    self.assertIs(node.get("additionalProperties"), False)
                    self.assertEqual(sorted(node["required"]), sorted(node["properties"]))
                for v in node.values():
                    walk(v)
            elif isinstance(node, list):
                for v in node:
                    walk(v)

        walk(OUTPUT_SCHEMA)

    def test_request_places_pdf_before_text_and_caches_system(self):
        sources = [Source("text", "http://example.com", "本文"), Source("pdf", "a.pdf", b"%PDF-1.4 test")]
        req = build_request(self.master, sources, "ヒント", "claude-opus-5-5", "medium")
        content = req["messages"][0]["content"]
        self.assertEqual([b["type"] for b in content], ["document", "text", "text"])
        self.assertIn("ヒント", content[-1]["text"])
        self.assertEqual(req["system"][0]["cache_control"], {"type": "ephemeral"})
        self.assertEqual(req["output_config"]["format"]["type"], "json_schema")
        self.assertNotIn("thinking", req)
        self.assertNotIn("temperature", req)

    def test_parse_response(self):
        self.assertEqual(parse_response(fake_response('{"a": 1}')), {"a": 1})
        with self.assertRaises(SelectionError):
            parse_response(fake_response(stop_reason="refusal", category="cyber"))
        with self.assertRaises(SelectionError):
            parse_response(fake_response("{}", stop_reason="max_tokens"))
        with self.assertRaises(SelectionError):
            parse_response(fake_response("not json"))
        with self.assertRaises(SelectionError):
            parse_response(fake_response(None))

    def test_call_model_routes_by_fallback_flag(self):
        calls = []

        def make(name):
            return SimpleNamespace(create=lambda **kw: calls.append((name, kw)) or "ok")

        client = SimpleNamespace(messages=make("std"), beta=SimpleNamespace(messages=make("beta")))
        call_model(client, {"model": "m"}, fallback=True)
        call_model(client, {"model": "m"}, fallback=False)
        self.assertEqual(calls[0][0], "beta")
        self.assertEqual(calls[0][1]["betas"], [FALLBACK_BETA])
        self.assertEqual(calls[0][1]["fallbacks"], "default")
        self.assertEqual(calls[1][0], "std")
        self.assertNotIn("fallbacks", calls[1][1])


class SourcesTest(unittest.TestCase):
    def test_html_to_text_skips_scripts_and_keeps_meta(self):
        html = (
            "<html><head><title>製品A</title><meta name='description' content='説明文'>"
            "<style>.x{}</style></head><body><script>var a=1;</script>"
            "<h1>見出し</h1><p>本文です</p></body></html>"
        )
        text = html_to_text(html)
        self.assertIn("[title] 製品A", text)
        self.assertIn("[description] 説明文", text)
        self.assertIn("見出し", text)
        self.assertIn("本文です", text)
        self.assertNotIn("var a", text)
        self.assertNotIn(".x{}", text)


class CliTest(unittest.TestCase):
    def run_cli(self, argv):
        out, err = io.StringIO(), io.StringIO()
        with redirect_stdout(out), redirect_stderr(err):
            code = cli.main(argv)
        return code, out.getvalue(), err.getvalue()

    def test_mock_end_to_end_json(self):
        code, out, _ = self.run_cli(["--mock-response", str(SAMPLE), "--json"])
        self.assertEqual(code, 0)
        data = json.loads(out)
        self.assertEqual(data["query"]["must"][0]["tag_id"], "2209")
        self.assertEqual(data["query"]["estimate"]["count"], 41229)

    def test_mock_report_text(self):
        code, out, _ = self.run_cli(["--mock-response", str(SAMPLE)])
        self.assertEqual(code, 0)
        self.assertIn("■ 推定件数", out)

    def test_dry_run_makes_no_api_call(self):
        code, out, _ = self.run_cli(["--text", "テスト商品の説明", "--dry-run"])
        self.assertEqual(code, 0)
        self.assertIn("システムプロンプト", out)

    def test_missing_file_is_reported(self):
        code, _, err = self.run_cli(["/nonexistent/file.pdf", "--dry-run"])
        self.assertEqual(code, 1)
        self.assertIn("見つかりません", err)


if __name__ == "__main__":
    unittest.main()
