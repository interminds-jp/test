(function () {
  'use strict';

  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  var form = document.getElementById('analyze-form');
  var submitBtn = document.getElementById('submit');
  var statusEl = document.getElementById('status');
  var resultPanel = document.getElementById('result-panel');
  var resultEl = document.getElementById('result');
  var lastResult = null;

  var SELL_MODE = {
    sell_to_users: '商品を使う企業に売る',
    sell_to_resellers: '商品を扱う企業（代理店・販売店）に売る',
    both: '使う企業・扱う企業の両方'
  };
  var KIND = {
    field: '分野',
    activity: '活動',
    intent: 'インテント',
    new_move: '新しい動き',
    trend: 'トレンド'
  };

  // textContent だけで DOM を組み立てる（モデル出力・外部ページ由来の文字列を HTML として解釈しない）
  function el(tag, props, children) {
    var node = document.createElement(tag);
    Object.keys(props || {}).forEach(function (k) {
      if (k === 'className') node.className = props[k];
      else if (k === 'text') node.textContent = props[k];
      else node.setAttribute(k, props[k]);
    });
    (children || []).forEach(function (c) {
      if (c) node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return node;
  }

  function join(items) {
    return items && items.length ? items.join('、') : '（なし）';
  }

  function num(n) {
    return Number(n).toLocaleString('ja-JP');
  }

  function kv(rows) {
    var dl = el('dl', { className: 'kv' });
    rows.forEach(function (r) {
      dl.appendChild(el('dt', { text: r[0] }));
      dl.appendChild(el('dd', { text: r[1] }));
    });
    return dl;
  }

  function tagItem(t, role) {
    var head = el('div', { className: 'tag-head' }, [
      el('span', { className: 'tag-id', text: t.tag_id }),
      el('span', { className: 'tag-name', text: t.name }),
      el('span', { className: 'chip', text: KIND[t.kind] || t.kind }),
      el('span', { className: 'tag-meta', text: num(t.company_count) + '社' + (t.weight ? '・重み' + t.weight : '') })
    ]);
    return el('li', { className: 'tag ' + role }, [head, el('p', { className: 'tag-reason', text: t.reason })]);
  }

  function tagSection(title, items, role) {
    var wrap = el('div', {}, [el('h3', { text: title + '（' + items.length + '）' })]);
    if (!items.length) {
      wrap.appendChild(el('p', { className: 'muted small', text: '（なし）' }));
      return wrap;
    }
    var ul = el('ul', { className: 'tag-list' });
    items.forEach(function (t) { ul.appendChild(tagItem(t, role)); });
    wrap.appendChild(ul);
    return wrap;
  }

  function render(r) {
    var p = r.product, h = r.target_hypothesis, q = r.query, est = q.estimate;
    var root = document.createDocumentFragment();

    if (r.meta.mock) {
      root.appendChild(el('div', { className: 'banner mock', text: 'モック表示：API を呼ばず、サンプル出力を使った表示です。実際の選定結果ではありません。' }));
    }

    root.appendChild(el('h3', { text: '商品' }));
    root.appendChild(kv([
      ['名称', p.name],
      ['概要', p.summary],
      ['カテゴリ', p.category],
      ['買い手の課題', join(p.buyer_problems)]
    ]));

    root.appendChild(el('h3', { text: 'ターゲット仮説' }));
    root.appendChild(kv([
      ['買い手', SELL_MODE[h.sell_mode] || h.sell_mode],
      ['業種', join(h.industries)],
      ['職種', join(h.job_titles)],
      ['規模', h.company_size || '（不明）'],
      ['取扱品目', join(h.handled_items)],
      ['キーワード', join(h.keywords)]
    ]));

    root.appendChild(tagSection('must：AND 条件', q.must, 'must'));
    root.appendChild(tagSection('should：加点条件', q.should, 'should'));
    root.appendChild(tagSection('exclude：除外条件', q.exclude, 'exclude'));
    root.appendChild(el('p', { className: 'muted small', text: q.scoring_rule }));

    root.appendChild(el('h3', { text: '推定件数' }));
    root.appendChild(el('div', {
      className: 'estimate',
      text: est.count === null ? '算出できません' : '約 ' + num(est.count) + ' 社'
    }));
    root.appendChild(el('p', { className: 'muted small', text: '目標 ' + num(q.min_count) + ' 社以上 ／ 方式：' + est.method }));
    if (q.relaxation_steps.length) {
      var ul = el('ul', { className: 'plain' });
      q.relaxation_steps.forEach(function (s) {
        ul.appendChild(el('li', {
          text: '[' + s.demoted_tag_id + '] ' + s.demoted_tag_name + ' を must → should に降格（' +
            num(s.estimate_before) + ' → ' + num(s.estimate_after) + ' 社）'
        }));
      });
      root.appendChild(el('h3', { text: '条件の緩和' }));
      root.appendChild(ul);
    }

    if (r.strategy_notes) {
      root.appendChild(el('h3', { text: '選定の考え方' }));
      root.appendChild(el('p', { text: r.strategy_notes }));
    }

    var notes = (r.caveats || []).concat((r.meta.warnings || []).concat(q.warnings || []).map(function (w) { return '（システム）' + w; }));
    if (notes.length) {
      root.appendChild(el('h3', { text: '注意' }));
      var nl = el('ul', { className: 'plain' });
      notes.forEach(function (n) { nl.appendChild(el('li', { text: n })); });
      root.appendChild(nl);
    }

    if (r.usage) {
      root.appendChild(el('p', {
        className: 'muted small',
        text: 'モデル: ' + r.meta.model + ' ／ 入力 ' + num(r.usage.input_tokens || 0) + ' tok・出力 ' + num(r.usage.output_tokens || 0) +
          ' tok・キャッシュ読込 ' + num(r.usage.cache_read_input_tokens || 0) + ' tok'
      }));
    }

    resultEl.replaceChildren(root);
    resultPanel.hidden = false;
    resultPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function showError(message) {
    resultEl.replaceChildren(el('div', { className: 'banner warn', role: 'alert', text: message }));
    resultPanel.hidden = false;
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    submitBtn.disabled = true;
    statusEl.textContent = '解析中です。数十秒かかることがあります…';

    fetch('api.php?action=analyze', {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrf },
      body: new FormData(form),
      credentials: 'same-origin'
    })
      .then(function (res) {
        return res.json().catch(function () { return { ok: false, error: 'サーバーの応答を解釈できませんでした（HTTP ' + res.status + '）。' }; })
          .then(function (data) { return { status: res.status, data: data }; });
      })
      .then(function (r) {
        if (r.status === 401) { location.reload(); return; }
        if (r.data.ok) {
          lastResult = r.data.result;
          render(lastResult);
          statusEl.textContent = '完了しました。';
        } else {
          showError(r.data.error || 'エラーが発生しました。');
          statusEl.textContent = '';
        }
      })
      .catch(function () {
        showError('通信に失敗しました。ネットワークを確認して再試行してください。');
        statusEl.textContent = '';
      })
      .then(function () { submitBtn.disabled = false; });
  });

  document.getElementById('download').addEventListener('click', function () {
    if (!lastResult) return;
    var blob = new Blob([JSON.stringify(lastResult, null, 2)], { type: 'application/json' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'tagfinder-result.json';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(a.href);
  });

  document.getElementById('logout').addEventListener('click', function () {
    fetch('api.php?action=logout', { method: 'POST', headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' })
      .then(function () { location.href = './'; });
  });
})();
