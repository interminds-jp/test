import re
import urllib.error
import urllib.request
from dataclasses import dataclass
from html.parser import HTMLParser
from pathlib import Path

MAX_TEXT_CHARS = 150_000
MAX_PDF_BYTES = 32 * 1024 * 1024
FETCH_TIMEOUT_SEC = 30
USER_AGENT = "Mozilla/5.0 (compatible; tagfinder/0.1)"


class SourceError(Exception):
    pass


@dataclass
class Source:
    kind: str  # "pdf" | "text"
    label: str
    data: object  # pdf: bytes / text: str


class _TextExtractor(HTMLParser):
    SKIP = {"script", "style", "noscript", "svg", "template"}
    BLOCK = {
        "p", "div", "br", "li", "ul", "ol", "tr", "table", "section", "article",
        "header", "footer", "h1", "h2", "h3", "h4", "h5", "h6", "dt", "dd",
    }

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.parts = []
        self.title = ""
        self.description = ""
        self._skip_depth = 0
        self._in_title = False

    def handle_starttag(self, tag, attrs):
        if tag in self.SKIP:
            self._skip_depth += 1
        elif tag == "title":
            self._in_title = True
        elif tag == "meta":
            a = {k.lower(): (v or "") for k, v in attrs}
            name = a.get("name", "").lower() or a.get("property", "").lower()
            if name in ("description", "og:description") and not self.description:
                self.description = a.get("content", "").strip()
        if tag in self.BLOCK:
            self.parts.append("\n")

    def handle_endtag(self, tag):
        if tag in self.SKIP and self._skip_depth:
            self._skip_depth -= 1
        elif tag == "title":
            self._in_title = False
        if tag in self.BLOCK:
            self.parts.append("\n")

    def handle_data(self, data):
        if self._in_title:
            self.title += data
        elif not self._skip_depth:
            self.parts.append(data)


def html_to_text(html):
    p = _TextExtractor()
    p.feed(html)
    body = "".join(p.parts)
    body = re.sub(r"[ \t　]+", " ", body)
    body = re.sub(r"\n\s*\n+", "\n", body).strip()
    head = []
    if p.title.strip():
        head.append(f"[title] {p.title.strip()}")
    if p.description:
        head.append(f"[description] {p.description}")
    return "\n".join(head + [body]) if head else body


def _decode(raw, content_type):
    candidates = []
    m = re.search(r"charset=([\w\-]+)", content_type or "", re.I)
    if m:
        candidates.append(m.group(1))
    m = re.search(rb'<meta[^>]+charset=["\']?([\w\-]+)', raw[:4096], re.I)
    if m:
        candidates.append(m.group(1).decode("ascii", "ignore"))
    candidates += ["utf-8", "shift_jis", "euc-jp"]
    for enc in candidates:
        try:
            return raw.decode(enc)
        except (LookupError, UnicodeDecodeError):
            continue
    return raw.decode("utf-8", errors="replace")


def _cap_text(text, label, warnings):
    if len(text) > MAX_TEXT_CHARS:
        warnings.append(
            f"{label}: 本文が {len(text):,} 文字あり、先頭 {MAX_TEXT_CHARS:,} 文字のみ使用しました。"
            "後半の情報は選定に反映されていません。"
        )
        return text[:MAX_TEXT_CHARS]
    return text


def _pdf_source(raw, label):
    if len(raw) > MAX_PDF_BYTES:
        raise SourceError(f"{label}: PDF が 32MB を超えています（{len(raw):,} bytes）。分割してください。")
    return Source("pdf", label, raw)


def fetch_url(url, warnings):
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT, "Accept-Language": "ja,en;q=0.8"})
    try:
        with urllib.request.urlopen(req, timeout=FETCH_TIMEOUT_SEC) as resp:
            raw = resp.read()
            ctype = resp.headers.get("Content-Type", "")
    except urllib.error.HTTPError as e:
        raise SourceError(f"{url}: HTTP {e.code} で取得できませんでした。") from e
    except (urllib.error.URLError, TimeoutError, OSError) as e:
        raise SourceError(f"{url}: 取得に失敗しました（{e}）。") from e

    if "application/pdf" in ctype.lower() or raw[:5] == b"%PDF-":
        return _pdf_source(raw, url)
    text = html_to_text(_decode(raw, ctype))
    if len(text) < 200:
        warnings.append(
            f"{url}: 取得できた本文が {len(text)} 文字と少なく、JavaScript で描画されるページの可能性があります。"
            "PDF や貼り付けテキストでの入力を検討してください。"
        )
    return Source("text", url, _cap_text(text, url, warnings))


def load_source(spec, warnings):
    """URL / PDF ファイル / テキストファイルを自動判別して Source にする。"""
    if spec.startswith(("http://", "https://")):
        return fetch_url(spec, warnings)
    path = Path(spec).expanduser()
    if not path.is_file():
        raise SourceError(f"{spec}: ファイルが見つかりません。")
    raw = path.read_bytes()
    if path.suffix.lower() == ".pdf" or raw[:5] == b"%PDF-":
        return _pdf_source(raw, str(path))
    if path.suffix.lower() in (".html", ".htm"):
        text = html_to_text(_decode(raw, ""))
    else:
        text = _decode(raw, "")
    return Source("text", str(path), _cap_text(text, str(path), warnings))


def inline_text_source(text, warnings):
    return Source("text", "(直接入力)", _cap_text(text, "(直接入力)", warnings))
