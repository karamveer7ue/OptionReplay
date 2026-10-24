/* Dependency-free SVG charts: payoff diagram, P&L-over-time line, and template thumbnails. */
(function () {
  const NS = 'http://www.w3.org/2000/svg';
  const el = (tag, attrs, parent) => { const e = document.createElementNS(NS, tag); for (const k in attrs) e.setAttribute(k, attrs[k]); if (parent) parent.appendChild(e); return e; };
  const fmtNum = (v, d = 0) => (v === null || v === undefined || isNaN(v)) ? '–' : v.toLocaleString('en-IN', { minimumFractionDigits: d, maximumFractionDigits: d });
  const fmtMoney = (v) => (v < 0 ? '−' : '') + '₹' + fmtNum(Math.abs(v));
  const niceStep = (range, target) => { const raw = range / target; const p = Math.pow(10, Math.floor(Math.log10(raw))); const n = raw / p; return (n < 1.5 ? 1 : n < 3.5 ? 2 : n < 7.5 ? 5 : 10) * p; };

  function clear(svg) { while (svg.firstChild) svg.removeChild(svg.firstChild); }
  function size(svg) { const r = svg.getBoundingClientRect(); return { w: Math.max(200, r.width), h: Math.max(120, r.height) }; }

  /** Split a polyline into profit/loss polygons against y=0 (in data space). */
  function splitAreas(xs, ys) {
    const pos = [], neg = [];
    let cur = null, curSign = 0;
    const push = () => { if (cur && cur.length > 1) (curSign > 0 ? pos : neg).push(cur); cur = null; curSign = 0; };
    for (let i = 0; i < xs.length; i++) {
      const s = Math.sign(ys[i]) || curSign || 1;
      if (i > 0 && Math.sign(ys[i]) !== 0 && curSign !== 0 && Math.sign(ys[i]) !== curSign) {
        const x0 = xs[i - 1], y0 = ys[i - 1], x1 = xs[i], y1 = ys[i];
        const xz = x0 + (x1 - x0) * (-y0) / (y1 - y0);
        cur.push([xz, 0]); push(); cur = [[xz, 0]]; curSign = Math.sign(ys[i]);
      }
      if (!cur) { cur = []; curSign = s; }
      cur.push([xs[i], ys[i]]);
    }
    push();
    return { pos, neg };
  }

  /**
   * Horizontal drag selection. Calls onZoom(xa, xb) in SVG pixel space when the user releases after a
   * drag wider than 6px; a plain click is ignored so hover tooltips keep working.
   */
  function attachSelection(svg, m, w, h, onZoom) {
    const rect = el('rect', { class: 'select-rect', y: m.t, height: h - m.t - m.b, x: 0, width: 0, visibility: 'hidden' }, svg);
    let startX = null;
    const px = (ev) => { const r = svg.getBoundingClientRect(); return (ev.clientX - r.left) * (w / r.width); };
    svg.addEventListener('mousedown', (ev) => { if (ev.button !== 0) return; startX = Math.max(m.l, Math.min(w - m.r, px(ev))); ev.preventDefault(); });
    svg.addEventListener('mousemove', (ev) => {
      if (startX === null) return;
      const x = Math.max(m.l, Math.min(w - m.r, px(ev)));
      rect.setAttribute('x', Math.min(startX, x)); rect.setAttribute('width', Math.abs(x - startX)); rect.setAttribute('visibility', 'visible');
    });
    const finish = (ev) => {
      if (startX === null) return;
      const x = Math.max(m.l, Math.min(w - m.r, px(ev)));
      rect.setAttribute('visibility', 'hidden');
      const a = Math.min(startX, x), b = Math.max(startX, x);
      startX = null;
      if (b - a > 6) onZoom(a, b);
    };
    svg.addEventListener('mouseup', finish);
    svg.addEventListener('mouseleave', (ev) => { if (startX !== null) finish(ev); });
  }

  /**
   * Payoff chart.
   * data: { xs:number[], expiry:number[], t0:number[]|null, spot:number, breakevens:number[], lotLabel:string }
   */
  function drawPayoff(svg, tip, data, opts = {}) {
    clear(svg);
    const { w, h } = size(svg);
    const m = { t: 14, r: 18, b: 28, l: 64 };
    const iw = w - m.l - m.r, ih = h - m.t - m.b;
    svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
    if (!data || !data.xs.length) { el('text', { x: w / 2, y: h / 2, 'text-anchor': 'middle' }, svg).textContent = 'Add legs to see the payoff.'; return; }
    const all = data.expiry.concat(data.t0 || []);
    let yMin = Math.min(0, ...all), yMax = Math.max(0, ...all);
    if (yMax - yMin < 1) { yMax += 1; yMin -= 1; }
    const pad = (yMax - yMin) * 0.08; yMin -= pad; yMax += pad;
    const xMin = data.xs[0], xMax = data.xs[data.xs.length - 1];
    const X = (v) => m.l + (v - xMin) / (xMax - xMin) * iw;
    const Y = (v) => m.t + (yMax - v) / (yMax - yMin) * ih;

    const grid = el('g', { class: 'grid' }, svg);
    const ys = niceStep(yMax - yMin, 6);
    for (let v = Math.ceil(yMin / ys) * ys; v <= yMax; v += ys) {
      el('line', { x1: m.l, x2: w - m.r, y1: Y(v), y2: Y(v) }, grid);
      el('text', { x: m.l - 8, y: Y(v) + 4, 'text-anchor': 'end' }, svg).textContent = fmtMoney(v).replace('₹', '');
    }
    const xsStep = niceStep(xMax - xMin, 7);
    for (let v = Math.ceil(xMin / xsStep) * xsStep; v <= xMax; v += xsStep) {
      el('line', { x1: X(v), x2: X(v), y1: m.t, y2: h - m.b, class: 'grid' }, grid);
      el('text', { x: X(v), y: h - m.b + 16, 'text-anchor': 'middle' }, svg).textContent = fmtNum(v);
    }
    el('line', { x1: m.l, x2: w - m.r, y1: Y(0), y2: Y(0), class: 'zero' }, svg);

    const areas = splitAreas(data.xs, data.expiry);
    const poly = (pts, cls) => el('path', { d: 'M' + pts.map(([x, y]) => `${X(x)},${Y(y)}`).join('L') + `L${X(pts[pts.length - 1][0])},${Y(0)}L${X(pts[0][0])},${Y(0)}Z`, class: cls }, svg);
    areas.pos.forEach((p) => poly(p, 'profit'));
    areas.neg.forEach((p) => poly(p, 'loss'));

    const path = (arr, cls) => el('path', { d: arr.map((v, i) => (i ? 'L' : 'M') + X(data.xs[i]) + ',' + Y(v)).join(''), class: cls }, svg);
    path(data.expiry, 'expiry-line');
    if (data.t0) path(data.t0, 't0-line');

    let lastLabelX = -Infinity, row = 0;
    (data.breakevens || []).filter((b) => b >= xMin && b <= xMax).sort((a, b) => a - b).forEach((b) => {
      el('line', { x1: X(b), x2: X(b), y1: m.t, y2: h - m.b, class: 'be' }, svg);
      row = X(b) - lastLabelX < 56 ? 1 - row : 0; // stagger labels that would collide
      lastLabelX = X(b);
      el('text', { x: X(b), y: m.t + 10 + row * 12, 'text-anchor': 'middle', class: 'be-label' }, svg).textContent = fmtNum(b);
    });
    // Expected-move bands: [{x, label, cls}] drawn as vertical dashed lines with a label at the bottom.
    (data.bands || []).forEach((bd) => {
      if (bd.x < xMin || bd.x > xMax) return;
      el('line', { x1: X(bd.x), x2: X(bd.x), y1: m.t, y2: h - m.b, class: 'sd ' + bd.cls }, svg);
      el('text', { x: X(bd.x), y: h - m.b - 8 - (bd.row || 0) * 12, 'text-anchor': 'middle', class: 'sd-label ' + bd.cls }, svg).textContent = bd.label;
    });
    if (data.spot >= xMin && data.spot <= xMax) {
      el('line', { x1: X(data.spot), x2: X(data.spot), y1: m.t, y2: h - m.b, class: 'spot-line' }, svg);
      el('text', { x: X(data.spot) + 4, y: h - m.b - 6, class: 'spot-label' }, svg).textContent = 'Spot ' + fmtNum(data.spot);
    }

    // hover
    const cursor = el('line', { y1: m.t, y2: h - m.b, class: 'cursor', visibility: 'hidden' }, svg);
    const dot1 = el('circle', { r: 3.5, fill: 'var(--text)', visibility: 'hidden' }, svg);
    const dot2 = el('circle', { r: 3.5, fill: 'var(--accent)', visibility: 'hidden' }, svg);
    svg.onmousemove = (ev) => {
      const rect = svg.getBoundingClientRect();
      const px = (ev.clientX - rect.left) * (w / rect.width);
      if (px < m.l || px > w - m.r) { svg.onmouseleave(); return; }
      const xv = xMin + (px - m.l) / iw * (xMax - xMin);
      let i = 0; while (i < data.xs.length - 1 && data.xs[i + 1] < xv) i++;
      cursor.setAttribute('x1', X(data.xs[i])); cursor.setAttribute('x2', X(data.xs[i])); cursor.setAttribute('visibility', 'visible');
      dot1.setAttribute('cx', X(data.xs[i])); dot1.setAttribute('cy', Y(data.expiry[i])); dot1.setAttribute('visibility', 'visible');
      let html = `<b>${fmtNum(data.xs[i])}</b> · At expiry ${fmtMoney(data.expiry[i])}`;
      if (data.t0) { dot2.setAttribute('cx', X(data.xs[i])); dot2.setAttribute('cy', Y(data.t0[i])); dot2.setAttribute('visibility', 'visible'); html += ` · T+0 ${fmtMoney(data.t0[i])}`; }
      tip.innerHTML = html; tip.hidden = false;
      tip.style.left = (X(data.xs[i]) * rect.width / w) + 'px'; tip.style.top = (Y(data.expiry[i]) * rect.height / h) + 'px';
    };
    svg.onmouseleave = () => { tip.hidden = true; [cursor, dot1, dot2].forEach((d) => d.setAttribute('visibility', 'hidden')); };
    if (opts.onZoom) attachSelection(svg, m, w, h, (a, b) => opts.onZoom(xMin + (a - m.l) / iw * (xMax - xMin), xMin + (b - m.l) / iw * (xMax - xMin)));
  }

  /** P&L over time. points: [{t:Date, v:number, spot:number}], marks: [{t:Date,label}] */
  function drawPnl(svg, tip, points, marks, opts = {}) {
    clear(svg);
    const { w, h } = size(svg);
    const m = { t: 14, r: 18, b: 30, l: 64 };
    const iw = w - m.l - m.r, ih = h - m.t - m.b;
    svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
    if (!points || points.length < 2) { el('text', { x: w / 2, y: h / 2, 'text-anchor': 'middle' }, svg).textContent = points && points.length === 1 ? 'Step forward in time to build the P&L path.' : 'Add legs, then step forward in time.'; return; }
    let yMin = Math.min(0, ...points.map((p) => p.v)), yMax = Math.max(0, ...points.map((p) => p.v));
    if (yMax - yMin < 1) { yMax += 1; yMin -= 1; }
    const pad = (yMax - yMin) * 0.1; yMin -= pad; yMax += pad;
    const X = (i) => m.l + i / (points.length - 1) * iw;
    const Y = (v) => m.t + (yMax - v) / (yMax - yMin) * ih;
    const grid = el('g', { class: 'grid' }, svg);
    const ys = niceStep(yMax - yMin, 6);
    for (let v = Math.ceil(yMin / ys) * ys; v <= yMax; v += ys) { el('line', { x1: m.l, x2: w - m.r, y1: Y(v), y2: Y(v) }, grid); el('text', { x: m.l - 8, y: Y(v) + 4, 'text-anchor': 'end' }, svg).textContent = fmtMoney(v).replace('₹', ''); }
    const labelEvery = Math.max(1, Math.floor(points.length / 6));
    const multiDay = points[0].t.toDateString() !== points[points.length - 1].t.toDateString();
    points.forEach((p, i) => { if (i % labelEvery === 0) el('text', { x: X(i), y: h - m.b + 16, 'text-anchor': 'middle' }, svg).textContent = multiDay ? p.t.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' }) + ' ' + p.t.toTimeString().slice(0, 5) : p.t.toTimeString().slice(0, 5); });
    el('line', { x1: m.l, x2: w - m.r, y1: Y(0), y2: Y(0), class: 'zero' }, svg);
    const areas = splitAreas(points.map((_, i) => i), points.map((p) => p.v));
    const poly = (pts, cls) => el('path', { d: 'M' + pts.map(([x, y]) => `${X(x)},${Y(y)}`).join('L') + `L${X(pts[pts.length - 1][0])},${Y(0)}L${X(pts[0][0])},${Y(0)}Z`, class: cls }, svg);
    areas.pos.forEach((p) => poly(p, 'pnl-area-pos')); areas.neg.forEach((p) => poly(p, 'pnl-area-neg'));
    el('path', { d: points.map((p, i) => (i ? 'L' : 'M') + X(i) + ',' + Y(p.v)).join(''), class: 'pnl-line' }, svg);
    (marks || []).forEach((mk) => { let i = points.findIndex((p) => p.t >= mk.t); if (i < 0) return; el('line', { x1: X(i), x2: X(i), y1: m.t, y2: h - m.b, class: 'be' }, svg); el('text', { x: X(i) + 3, y: m.t + 10, class: 'be-label' }, svg).textContent = mk.label; });
    const cursor = el('line', { y1: m.t, y2: h - m.b, class: 'cursor', visibility: 'hidden' }, svg);
    const dot = el('circle', { r: 3.5, fill: 'var(--text)', visibility: 'hidden' }, svg);
    svg.onmousemove = (ev) => {
      const rect = svg.getBoundingClientRect(); const px = (ev.clientX - rect.left) * (w / rect.width);
      const i = Math.max(0, Math.min(points.length - 1, Math.round((px - m.l) / iw * (points.length - 1))));
      const p = points[i];
      cursor.setAttribute('x1', X(i)); cursor.setAttribute('x2', X(i)); cursor.setAttribute('visibility', 'visible');
      dot.setAttribute('cx', X(i)); dot.setAttribute('cy', Y(p.v)); dot.setAttribute('visibility', 'visible');
      tip.innerHTML = `<b>${p.t.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' })} ${p.t.toTimeString().slice(0, 5)}</b> · ${fmtMoney(p.v)}` + (p.spot ? ` · Spot ${fmtNum(p.spot)}` : '');
      tip.hidden = false; tip.style.left = (X(i) * rect.width / w) + 'px'; tip.style.top = (Y(p.v) * rect.height / h) + 'px';
    };
    svg.onmouseleave = () => { tip.hidden = true; cursor.setAttribute('visibility', 'hidden'); dot.setAttribute('visibility', 'hidden'); };
    if (opts.onZoom) attachSelection(svg, m, w, h, (a, b) => opts.onZoom(Math.round((a - m.l) / iw * (points.length - 1)), Math.round((b - m.l) / iw * (points.length - 1))));
  }

  /** Thumbnail of a template payoff shape (unit strikes, unit premiums). */
  function templateSvg(tpl) {
    const legs = tpl.legs;
    const prem = (kind, off) => Math.max(0.15, 1.2 - 0.25 * Math.abs(off)) * 1; // crude premium proxy
    const xs = []; for (let x = -10; x <= 10; x += 0.25) xs.push(x);
    const ys = xs.map((x) => legs.reduce((s, l) => {
      const k = l.offset, intrinsic = l.kind === 'CE' ? Math.max(0, x - k) : Math.max(0, k - x);
      const p = prem(l.kind, k) + (l.exp ? 0.6 : 0);
      return s + (l.side === 'B' ? 1 : -1) * (intrinsic - p) * (l.lots || 1);
    }, 0));
    const yMax = Math.max(...ys.map(Math.abs), 0.5);
    const W = 160, H = 80, X = (x) => (x + 10) / 20 * W, Y = (y) => H / 2 - (y / yMax) * (H / 2 - 6);
    const { pos, neg } = splitAreas(xs, ys);
    const area = (pts, cls) => `<path class="${cls}" d="M${pts.map(([x, y]) => `${X(x).toFixed(1)},${Y(y).toFixed(1)}`).join('L')}L${X(pts[pts.length - 1][0]).toFixed(1)},${Y(0)}L${X(pts[0][0]).toFixed(1)},${Y(0)}Z"/>`;
    const seg = (pts, cls) => `<path class="ln ${cls}" d="M${pts.map(([x, y]) => `${X(x).toFixed(1)},${Y(y).toFixed(1)}`).join('L')}"/>`;
    return `<svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="none"><line class="ax" x1="0" x2="${W}" y1="${Y(0)}" y2="${Y(0)}"/>${pos.map((p) => area(p, 'fill-p')).join('')}${neg.map((p) => area(p, 'fill-l')).join('')}${pos.map((p) => seg(p, 'ln-p')).join('')}${neg.map((p) => seg(p, 'ln-l')).join('')}</svg>`;
  }

  window.Charts = { drawPayoff, drawPnl, templateSvg, fmtNum, fmtMoney };
})();
