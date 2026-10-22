/* Options strategy simulator - main application. Vanilla JS, no build step. */
(function () {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const { fmtNum, fmtMoney } = window.Charts;
  const api = async (path, params) => {
    const url = path + '?' + new URLSearchParams(params).toString();
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    const json = await res.json().catch(() => ({ error: 'Bad response' }));
    if (!res.ok) throw new Error(json.error || res.statusText);
    return json;
  };
  const toast = (msg) => { const t = $('toast'); t.textContent = msg; t.hidden = false; clearTimeout(toast._t); toast._t = setTimeout(() => { t.hidden = true; }, 2600); };
  const b64e = (s) => btoa(unescape(encodeURIComponent(s))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const b64d = (s) => decodeURIComponent(escape(atob(s.replace(/-/g, '+').replace(/_/g, '/'))));
  const parseTs = (s) => new Date(s.replace(' ', 'T'));
  const fmtDate = (iso) => new Date(iso + 'T00:00').toLocaleDateString('en-IN', { day: '2-digit', month: 'short' });
  const fmtAt = (at) => { const d = parseTs(at); return d.toLocaleDateString('en-IN', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' }) + ' · ' + d.toTimeString().slice(0, 5); };

  /* ---------- Black-Scholes (client side, for T+0 curve) ---------- */
  const cdf = (x) => { const t = 1 / (1 + 0.2316419 * Math.abs(x)); const d = 0.3989422804014327 * Math.exp(-x * x / 2); const p = d * t * (0.319381530 + t * (-0.356563782 + t * (1.781477937 + t * (-1.821255978 + t * 1.330274429)))); return x >= 0 ? 1 - p : p; };
  const bs = (kind, s, k, t, r, v) => { if (t <= 0 || v <= 0) return Math.max(0, kind === 'CE' ? s - k : k - s); const d1 = (Math.log(s / k) + (r + v * v / 2) * t) / (v * Math.sqrt(t)); const d2 = d1 - v * Math.sqrt(t); return kind === 'CE' ? s * cdf(d1) - k * Math.exp(-r * t) * cdf(d2) : k * Math.exp(-r * t) * cdf(-d2) - s * cdf(-d1); };
  const yearsBetween = (fromAt, expiry) => Math.max(0, (new Date(expiry + 'T15:30:00') - parseTs(fromAt)) / (365 * 86400000));

  /* ---------- state ---------- */
  const S = {
    u: null, at: null, expiry: null, snap: null, chain: null, chainExpiry: null, legs: [], quotes: {}, name: '',
    view: 'payoff', autoplay: null, settings: null, spotChart: null, spotSeries: null, spotInterval: '5m', firstScroll: true,
  };
  const underlyings = () => window.APP.underlyings;
  const U = () => underlyings().find((x) => x.symbol === S.u);

  /* ---------- URL state ---------- */
  function encodeState() {
    return { u: S.u, at: S.at, n: S.name, e: S.expiry, legs: S.legs.map((l) => ({ i: l.id, k: l.kind, st: l.strike, ex: l.expiry, s: l.side, l: l.lots, ep: l.entryPrice, ea: l.entryAt, on: l.on ? 1 : 0 })) };
  }
  function writeHash() { try { history.replaceState(null, '', '#s=' + b64e(JSON.stringify(encodeState()))); } catch (e) { /* ignore */ } }
  function readHash() {
    const m = location.hash.match(/#s=([A-Za-z0-9_-]+)/);
    if (!m) return null;
    try { return JSON.parse(b64d(m[1])); } catch (e) { return null; }
  }
  function applyState(st) {
    if (!st) return;
    if (st.u && underlyings().some((x) => x.symbol === st.u)) S.u = st.u;
    if (st.at) S.at = st.at;
    S.name = st.n || '';
    S.expiry = st.e || null;
    S.legs = (st.legs || []).map((l) => ({ id: l.i, kind: l.k, strike: +l.st, expiry: l.ex, side: l.s, lots: +l.l || 1, entryPrice: +l.ep, entryAt: l.ea, on: l.on !== 0, lotSize: 0, now: null }));
  }

  /* ---------- underlying & time ---------- */
  function renderUnderlyingSwitch() {
    const box = $('underlying-switch'); box.innerHTML = '';
    underlyings().forEach((u) => {
      const b = document.createElement('button');
      b.textContent = u.symbol; b.className = u.symbol === S.u ? 'active' : '';
      b.disabled = !u.range || !u.range.max; b.title = b.disabled ? 'No data collected yet' : `${u.name}: data ${u.range.min} to ${u.range.max}`;
      b.onclick = () => { if (S.legs.length && !confirm('Switching underlying clears the current legs. Continue?')) return; S.u = u.symbol; S.legs = []; S.expiry = null; S.firstScroll = true; clampTime(); load(); };
      box.appendChild(b);
    });
  }
  function clampTime() {
    const u = U(); if (!u || !u.range || !u.range.max) return;
    const max = u.range.max.replace(' ', 'T').slice(0, 16), min = u.range.min.replace(' ', 'T').slice(0, 16);
    if (!S.at || S.at > max) S.at = max;
    if (S.at < min) S.at = min;
  }

  async function load() {
    const u = U(); if (!u) return;
    renderUnderlyingSwitch();
    try {
      S.snap = await api('/api/snapshot', { u: S.u, at: S.at });
    } catch (e) { toast(e.message); return; }
    S.at = S.snap.at;
    $('time-input').value = S.at; $('time-label').textContent = fmtAt(S.at) + (S.snap.is_trading_day ? '' : ' · holiday');
    renderMarketStrip();
    const exps = S.snap.expiries;
    if (!exps.some((e) => e.expiry === S.expiry)) S.expiry = exps.length ? exps[0].expiry : null;
    renderExpiryTabs();
    await Promise.all([loadChain(), refreshLegs()]);
    writeHash();
    if (S.view === 'pnl') loadPnl();
    if (S.view === 'spot') loadSpotChart();
  }

  function renderMarketStrip() {
    const sp = S.snap.spot, fut = S.snap.future;
    $('spot-value').textContent = sp.ltp ? fmtNum(sp.ltp, 2) : '–';
    const ch = $('spot-change');
    if (sp.change !== null && sp.change !== undefined) { ch.textContent = `${sp.change >= 0 ? '+' : ''}${fmtNum(sp.change, 2)} (${sp.change_pct >= 0 ? '+' : ''}${sp.change_pct}%)`; ch.className = 'mkt-change ' + (sp.change >= 0 ? 'pos' : 'neg'); } else { ch.textContent = ''; }
    $('fut-value').textContent = fut.price ? fmtNum(fut.price, 2) : '–';
    $('fut-expiry').textContent = fut.expiry ? fmtDate(fut.expiry) : '';
    $('fut-note').textContent = fut.synthetic ? 'synthetic (spot + carry)' : (fut.stale ? 'previous close' : 'exchange');
    $('lot-value').textContent = S.snap.lot_size;
    const r = S.snap.range; $('data-range').textContent = r.min ? `${r.min.slice(0, 10)} → ${r.max.slice(0, 16)}` : 'no data yet';
    document.querySelectorAll('.step').forEach((b) => { b.disabled = !r.max; });
  }

  function renderExpiryTabs() {
    const box = $('expiry-tabs'); box.innerHTML = '';
    if (!S.snap.expiries.length) { box.innerHTML = '<span class="muted small">No option contracts stored for this date yet.</span>'; return; }
    S.snap.expiries.forEach((e) => {
      const b = document.createElement('button');
      b.innerHTML = `${fmtDate(e.expiry)}<small>${e.dte}d</small>`; b.className = e.expiry === S.expiry ? 'active' : '';
      b.onclick = () => { S.expiry = e.expiry; S.firstScroll = true; renderExpiryTabs(); loadChain(); writeHash(); };
      box.appendChild(b);
    });
  }

  /* ---------- option chain ---------- */
  async function loadChain() {
    const body = $('chain-body');
    if (!S.expiry) { S.chain = null; body.innerHTML = '<tr><td class="muted center" colspan="9">No expiry available.</td></tr>'; return; }
    try { S.chain = await api('/api/chain', { u: S.u, at: S.at, expiry: S.expiry }); S.chainExpiry = S.expiry; } catch (e) { toast(e.message); return; }
    renderChain();
  }
  function legAt(id) { return S.legs.find((l) => l.id === id); }
  function renderChain() {
    if (!S.chain) return;
    const greeks = $('chain-greeks').checked, oiBars = $('chain-oi').checked;
    const spot = S.chain.spot, rows = S.chain.rows;
    const maxOi = Math.max(1, ...rows.flatMap((r) => [r.CE?.oi || 0, r.PE?.oi || 0]));
    const head = $('chain-head');
    const cols = [];
    if (greeks) cols.push('<th>Δ</th><th>Θ</th>');
    const anyOiHead = rows.some((r) => (r.CE && r.CE.oi) || (r.PE && r.PE.oi)) ? 'OI' : 'Vol';
    cols.push(`<th>IV</th><th>${anyOiHead}</th><th>Call LTP</th><th class="strike-h">Strike</th><th class="left">Put LTP</th><th class="left">${anyOiHead}</th><th class="left">IV</th>`);
    if (greeks) cols.push('<th class="left">Θ</th><th class="left">Δ</th>');
    head.innerHTML = cols.join('');
    const cell = (c, kind, side) => {
      if (!c) return `<td class="ltp ${kind.toLowerCase()} empty ${side}">–</td>`;
      const leg = legAt(c.id);
      const cls = ['ltp', kind.toLowerCase(), side, c.stale ? 'stale' : '', leg ? 'has-leg ' + (leg.side === 'B' ? 'buy' : 'sell') : ''].join(' ');
      const ltp = c.ltp === null ? '–' : fmtNum(c.ltp, 2);
      return `<td class="${cls}" data-id="${c.id}" data-kind="${kind}" title="${c.stale ? 'No trade yet today, showing previous close' : 'Click to add a leg'}"><span class="q">${ltp}<span class="bs"><b class="b" data-side="B">B</b><b class="s" data-side="S">S</b></span></span></td>`;
    };
    // Kotak does not populate OI in candles yet; fall back to the bar's traded volume.
    const anyOi = rows.some((r) => (r.CE && r.CE.oi) || (r.PE && r.PE.oi));
    const maxVol = Math.max(1, ...rows.flatMap((r) => [r.CE?.volume || 0, r.PE?.volume || 0]));
    const oi = (c, side) => {
      if (!c) return `<td class="oi ${side}"></td>`;
      const v = anyOi ? c.oi : c.volume, mx = anyOi ? maxOi : maxVol;
      const txt = v ? (v >= 1000 ? fmtNum(v / 1000, v >= 100000 ? 0 : 1) + 'k' : fmtNum(v)) : '–';
      return `<td class="oi ${side}" title="${anyOi ? 'Open interest' : 'Volume traded in the last minute'}">${oiBars && v ? `<div class="bar" style="width:${Math.max(2, v / mx * 100)}%"></div>` : ''}<span>${txt}</span></td>`;
    };
    const g = (v, d, side) => `<td class="greek ${side}">${v === null || v === undefined ? '–' : fmtNum(v, d)}</td>`;
    const html = rows.map((r) => {
      const cls = [r.strike === S.chain.atm ? 'atm' : '', spot && r.strike < spot ? 'itm-ce' : '', spot && r.strike > spot ? 'itm-pe' : ''].join(' ');
      let s = `<tr class="${cls}" data-strike="${r.strike}">`;
      if (greeks) s += g(r.CE?.delta, 2, 'ce') + g(r.CE?.theta, 1, 'ce');
      s += g(r.CE?.iv, 1, 'ce') + oi(r.CE, 'ce') + cell(r.CE, 'CE', 'ce');
      s += `<td class="strike">${fmtNum(r.strike)}</td>`;
      s += cell(r.PE, 'PE', 'pe') + oi(r.PE, 'pe') + g(r.PE?.iv, 1, 'pe');
      if (greeks) s += g(r.PE?.theta, 1, 'pe') + g(r.PE?.delta, 2, 'pe');
      return s + '</tr>';
    }).join('');
    $('chain-body').innerHTML = html || '<tr><td class="muted center" colspan="9">No contracts for this expiry.</td></tr>';
    if (S.firstScroll) { const atm = document.querySelector('#chain-body tr.atm'); const box = $('chain-scroll'); if (atm) { box.scrollTop = Math.max(0, atm.offsetTop - box.clientHeight / 2 + atm.offsetHeight / 2); S.firstScroll = false; } }
  }
  $('chain-body').addEventListener('click', (ev) => {
    const td = ev.target.closest('td.ltp'); if (!td || !td.dataset.id) return;
    const side = ev.target.dataset.side || 'B';
    const row = S.chain.rows.find((r) => String(r.strike) === td.closest('tr').dataset.strike);
    const c = row[td.dataset.kind];
    if (c.ltp === null) { toast('No price available for this contract at this time'); return; }
    addLeg({ id: c.id, kind: td.dataset.kind, strike: row.strike, expiry: S.chainExpiry, side, lots: 1, entryPrice: c.ltp, entryAt: S.at, on: true, lotSize: S.chain.lot_size });
  });
  $('chain-greeks').onchange = renderChain; $('chain-oi').onchange = renderChain;
  $('chain-oi').closest('label').lastChild.textContent = ' OI / volume bars';

  /* ---------- legs ---------- */
  function addLeg(leg) {
    const existing = S.legs.find((l) => l.id === leg.id);
    if (existing) {
      if (existing.side === leg.side) existing.lots += 1; else { existing.side = leg.side; existing.entryPrice = leg.entryPrice; existing.entryAt = leg.entryAt; }
    } else { S.legs.push(leg); }
    refreshLegs(); renderChain(); writeHash();
  }
  function removeLeg(id) { S.legs = S.legs.filter((l) => l.id !== id); refreshLegs(); renderChain(); writeHash(); }
  async function refreshLegs() {
    if (S.legs.length) {
      try {
        const q = await api('/api/quotes', { u: S.u, at: S.at, ids: S.legs.map((l) => l.id).join(',') });
        S.quotes = {}; q.quotes.forEach((x) => { S.quotes[x.id] = x; });
        S.legs.forEach((l) => { const x = S.quotes[l.id]; if (x) { l.now = x.ltp; l.lotSize = x.lot_size || l.lotSize; l.symbol = x.symbol; l.iv = x.iv; l.delta = x.delta; l.theta = x.theta; l.gamma = x.gamma; l.vega = x.vega; l.expired = x.expired; l.settled = !!x.settled; } });
      } catch (e) { toast(e.message); }
    }
    renderLegs(); renderPayoff();
    if (S.view === 'pnl') loadPnl();
  }
  const qty = (l) => l.lots * (l.lotSize || S.snap?.lot_size || 1);
  const sign = (l) => (l.side === 'B' ? 1 : -1);
  const legPnl = (l) => (l.now === null || l.now === undefined ? 0 : sign(l) * (l.now - l.entryPrice) * qty(l));
  function charges(l) {
    const c = S.settings?.charges; if (!c) return 0;
    const q = qty(l), entry = l.entryPrice * q, exit = (l.now ?? l.entryPrice) * q;
    const sellTurnover = l.side === 'S' ? entry : exit, buyTurnover = l.side === 'B' ? entry : exit, turnover = entry + exit;
    const brokerage = c.brokerage_per_order * 2, exch = turnover * c.exchange_txn_pct / 100, sebi = turnover * c.sebi_fee_pct / 100;
    return brokerage + exch + sebi + sellTurnover * c.stt_sell_pct / 100 + buyTurnover * c.stamp_duty_buy_pct / 100 + (brokerage + exch + sebi) * c.gst_pct / 100;
  }
  const activeLegs = () => S.legs.filter((l) => l.on);
  function renderLegs() {
    const body = $('legs-body'); $('legs-count').textContent = S.legs.length;
    if (!S.legs.length) { body.innerHTML = '<tr><td colspan="13" class="muted center">No legs yet. Click a premium in the chain or pick a ready-made strategy.</td></tr>'; $('legs-foot').innerHTML = ''; return; }
    body.innerHTML = S.legs.map((l) => {
      const pnl = legPnl(l);
      return `<tr class="${l.on ? '' : 'off'}" data-id="${l.id}">
        <td><input type="checkbox" class="toggle-leg" ${l.on ? 'checked' : ''} title="Include leg"></td>
        <td><button class="pill side-${l.side.toLowerCase()}" data-act="side">${l.side === 'B' ? 'BUY' : 'SELL'}</button></td>
        <td>${fmtDate(l.expiry)}${l.expired ? ' <span class="tag">expired</span>' : ''}</td><td>${fmtNum(l.strike)}</td><td>${l.kind}</td>
        <td><input type="number" class="lots" min="1" max="500" value="${l.lots}"></td>
        <td title="Entered ${fmtAt(l.entryAt)}">${fmtNum(l.entryPrice, 2)}</td>
        <td>${l.now === null || l.now === undefined ? '–' : fmtNum(l.now, 2)}${l.settled ? ' <small class="muted">settled</small>' : ''}</td>
        <td class="${pnl >= 0 ? 'pos' : 'neg'}"><b>${fmtMoney(pnl)}</b></td>
        <td>${l.iv ? l.iv.toFixed(1) : '–'}</td><td>${l.delta !== null && l.delta !== undefined ? (sign(l) * l.delta).toFixed(2) : '–'}</td><td>${l.theta !== null && l.theta !== undefined ? fmtNum(sign(l) * l.theta * qty(l), 0) : '–'}</td>
        <td><button class="btn btn-sm btn-ghost" data-act="remove" title="Remove leg">✕</button></td></tr>`;
    }).join('');
    const legs = activeLegs();
    const total = legs.reduce((s, l) => s + legPnl(l), 0), ch = legs.reduce((s, l) => s + charges(l), 0);
    const premium = legs.reduce((s, l) => s - sign(l) * l.entryPrice * qty(l), 0);
    const delta = legs.reduce((s, l) => s + (l.delta || 0) * sign(l) * qty(l), 0), theta = legs.reduce((s, l) => s + (l.theta || 0) * sign(l) * qty(l), 0);
    const vega = legs.reduce((s, l) => s + (l.vega || 0) * sign(l) * qty(l), 0), gamma = legs.reduce((s, l) => s + (l.gamma || 0) * sign(l) * qty(l), 0);
    const includeCharges = $('charges-toggle').checked;
    const net = total - (includeCharges ? ch : 0);
    $('legs-foot').innerHTML = `<span>Net premium <b>${premium >= 0 ? 'credit ' : 'debit '}${fmtMoney(Math.abs(premium))}</b></span><span>Current P&amp;L <b class="${net >= 0 ? 'pos' : 'neg'}">${fmtMoney(net)}</b></span><span>Charges <b>${fmtMoney(ch)}</b>${includeCharges ? '' : ' <span class="muted">(not included)</span>'}</span><span>Δ <b>${fmtNum(delta, 1)}</b></span><span>Γ <b>${fmtNum(gamma, 3)}</b></span><span>Θ/day <b>${fmtNum(theta, 0)}</b></span><span>Vega <b>${fmtNum(vega, 0)}</b></span>`;
  }
  $('legs-body').addEventListener('click', (ev) => {
    const tr = ev.target.closest('tr'); if (!tr) return; const l = legAt(+tr.dataset.id); if (!l) return;
    const act = ev.target.dataset.act;
    if (act === 'remove') removeLeg(l.id);
    if (act === 'side') { l.side = l.side === 'B' ? 'S' : 'B'; renderLegs(); renderPayoff(); renderChain(); writeHash(); }
  });
  $('legs-body').addEventListener('change', (ev) => {
    const tr = ev.target.closest('tr'); if (!tr) return; const l = legAt(+tr.dataset.id); if (!l) return;
    if (ev.target.classList.contains('lots')) l.lots = Math.max(1, Math.min(500, +ev.target.value || 1));
    if (ev.target.classList.contains('toggle-leg')) l.on = ev.target.checked;
    renderLegs(); renderPayoff(); writeHash(); if (S.view === 'pnl') loadPnl();
  });
  $('reset-legs').onclick = () => { if (!S.legs.length || confirm('Clear all legs?')) { S.legs = []; S.name = ''; $('strategy-name').value = ''; refreshLegs(); renderChain(); writeHash(); } };
  $('strategy-name').oninput = (e) => { S.name = e.target.value; writeHash(); };

  /* ---------- payoff ---------- */
  function payoffData() {
    const legs = activeLegs(); const spot = S.snap?.spot?.ltp;
    if (!legs.length || !spot) return null;
    const r = S.settings?.risk_free_rate ?? 0.065;
    const pct = Math.max(2, Math.min(40, +$('payoff-range').value || 8)) / 100;
    const n = 160, xs = [];
    for (let i = 0; i <= n; i++) xs.push(spot * (1 - pct) + spot * 2 * pct * i / n);
    const target = legs.reduce((m, l) => (l.expiry < m ? l.expiry : m), legs[0].expiry); // nearest expiry
    const targetAt = target + 'T15:30';
    const includeCharges = $('charges-toggle').checked;
    const ch = includeCharges ? legs.reduce((s, l) => s + charges(l), 0) : 0;
    const ivOf = (l) => (l.iv ? l.iv / 100 : 0.15);
    const valueAtExpiry = (x) => legs.reduce((s, l) => {
      const val = l.expiry === target ? Math.max(0, l.kind === 'CE' ? x - l.strike : l.strike - x) : bs(l.kind, x, l.strike, yearsBetween(targetAt, l.expiry), r, ivOf(l));
      return s + sign(l) * (val - l.entryPrice) * qty(l);
    }, 0) - ch;
    const expiry = xs.map(valueAtExpiry);
    const t0 = $('payoff-t0').checked ? xs.map((x) => legs.reduce((s, l) => s + sign(l) * (bs(l.kind, x, l.strike, yearsBetween(S.at, l.expiry), r, ivOf(l)) - l.entryPrice) * qty(l), 0) - ch) : null;
    // Breakevens and extremes over the whole payoff shape, not just the visible window:
    // evaluate at every strike plus far tails, and treat a still-rising tail as unlimited.
    const probes = [...new Set(legs.map((l) => l.strike))].sort((a, b) => a - b);
    const farL = Math.max(0.01, probes[0] * 0.5), farR = probes[probes.length - 1] * 1.5;
    const wide = [farL, ...probes, farR];
    const vals = wide.map(valueAtExpiry);
    const be = [];
    for (let i = 1; i < wide.length; i++) if (Math.sign(vals[i]) !== Math.sign(vals[i - 1]) && vals[i] !== vals[i - 1]) be.push(wide[i - 1] + (wide[i] - wide[i - 1]) * (-vals[i - 1]) / (vals[i] - vals[i - 1]));
    const tol = 1e-6;
    const slopeR = valueAtExpiry(farR * 1.1) - vals[vals.length - 1], slopeL = vals[0] - valueAtExpiry(farL * 0.5);
    const maxP = Math.max(...vals), minP = Math.min(...vals);
    const unlimitedProfit = slopeR > tol || slopeL < -tol;
    const unlimitedLoss = slopeR < -tol || slopeL > tol;
    return { xs, expiry, t0, spot, breakevens: be, maxProfit: unlimitedProfit ? null : maxP, maxLoss: unlimitedLoss ? null : minP, target };
  }
  function renderPayoff() {
    const d = payoffData();
    window.Charts.drawPayoff($('payoff-chart'), $('payoff-tip'), d);
    const m = $('metrics');
    if (!d) { m.innerHTML = ''; return; }
    const legs = activeLegs();
    const pnlNow = legs.reduce((s, l) => s + legPnl(l), 0) - ($('charges-toggle').checked ? legs.reduce((s, l) => s + charges(l), 0) : 0);
    const metric = (label, value, cls = '') => `<div class="metric"><span class="metric-label">${label}</span><span class="metric-value ${cls}">${value}</span></div>`;
    m.innerHTML = metric('Max profit', d.maxProfit === null ? 'Unlimited' : fmtMoney(d.maxProfit), 'pos') + metric('Max loss', d.maxLoss === null ? 'Unlimited' : fmtMoney(d.maxLoss), 'neg')
      + metric('Breakeven' + (d.breakevens.length > 1 ? 's' : ''), d.breakevens.length ? d.breakevens.map((b) => fmtNum(b)).join(' · ') : '–')
      + metric('Current P&L', fmtMoney(pnlNow), pnlNow >= 0 ? 'pos' : 'neg') + metric('Payoff at', fmtDate(d.target) + ' expiry');
  }
  ['payoff-t0', 'charges-toggle', 'payoff-range'].forEach((id) => { $(id).addEventListener('change', () => { renderLegs(); renderPayoff(); }); });
  $('payoff-range').addEventListener('input', renderPayoff);

  /* ---------- P&L over time ---------- */
  async function loadPnl() {
    const legs = activeLegs();
    if (!legs.length) { window.Charts.drawPnl($('pnl-chart'), $('pnl-tip'), [], []); $('pnl-metrics').innerHTML = ''; return; }
    const from = legs.reduce((m, l) => (l.entryAt < m ? l.entryAt : m), legs[0].entryAt);
    if (from >= S.at) { window.Charts.drawPnl($('pnl-chart'), $('pnl-tip'), [{ t: parseTs(S.at), v: 0 }], []); $('pnl-metrics').innerHTML = ''; return; }
    let data; try { data = await api('/api/series', { u: S.u, from, to: S.at, ids: legs.map((l) => l.id).join(',') }); } catch (e) { toast(e.message); return; }
    const last = {}; legs.forEach((l) => { last[l.id] = null; });
    const legSeries = {}; legs.forEach((l) => { legSeries[l.id] = (data.legs[l.id] || []).map(([t, c]) => [parseTs(t), c]); });
    const idx = {}; legs.forEach((l) => { idx[l.id] = 0; });
    const points = data.spot.map(([t, spot]) => {
      const ts = parseTs(t);
      let v = 0;
      legs.forEach((l) => {
        const ser = legSeries[l.id];
        while (idx[l.id] < ser.length && ser[idx[l.id]][0] <= ts) { last[l.id] = ser[idx[l.id]][1]; idx[l.id]++; }
        if (parseTs(l.entryAt) <= ts) v += sign(l) * ((last[l.id] ?? l.entryPrice) - l.entryPrice) * qty(l);
      });
      return { t: ts, v, spot };
    });
    const marks = legs.map((l) => ({ t: parseTs(l.entryAt), label: `${l.side} ${l.kind} ${fmtNum(l.strike)}` }));
    window.Charts.drawPnl($('pnl-chart'), $('pnl-tip'), points, marks);
    if (points.length) {
      const vals = points.map((p) => p.v), hi = Math.max(...vals), lo = Math.min(...vals);
      const metric = (label, value, cls = '') => `<div class="metric"><span class="metric-label">${label}</span><span class="metric-value ${cls}">${value}</span></div>`;
      $('pnl-metrics').innerHTML = metric('Peak', fmtMoney(hi), 'pos') + metric('Trough', fmtMoney(lo), 'neg') + metric('Now', fmtMoney(vals[vals.length - 1]), vals[vals.length - 1] >= 0 ? 'pos' : 'neg') + metric('Since', fmtAt(from));
    }
  }

  /* ---------- spot chart ---------- */
  function ensureSpotChart() {
    if (S.spotChart) return;
    const dark = document.documentElement.dataset.theme === 'dark';
    S.spotChart = LightweightCharts.createChart($('spot-chart'), {
      layout: { background: { color: 'transparent' }, textColor: dark ? '#8b9bb4' : '#64748b', fontFamily: 'Inter, system-ui, sans-serif' },
      grid: { vertLines: { color: dark ? '#1f2b40' : '#eef2f7' }, horzLines: { color: dark ? '#1f2b40' : '#eef2f7' } },
      rightPriceScale: { borderColor: dark ? '#334155' : '#cbd5e1' }, timeScale: { borderColor: dark ? '#334155' : '#cbd5e1', timeVisible: true, secondsVisible: false },
      crosshair: { mode: 0 }, autoSize: true, localization: { timeFormatter: (t) => new Date(t * 1000).toLocaleString('en-IN', { timeZone: 'Asia/Kolkata', day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false }) },
    });
    S.spotSeries = S.spotChart.addCandlestickSeries({ upColor: '#22c55e', downColor: '#ef4444', wickUpColor: '#22c55e', wickDownColor: '#ef4444', borderVisible: false });
  }
  async function loadSpotChart() {
    ensureSpotChart();
    const days = { '1m': 1, '5m': 4, '15m': 10, '1h': 45, '1d': 400 }[S.spotInterval];
    const from = new Date(parseTs(S.at)); from.setDate(from.getDate() - days);
    const fromStr = from.toISOString().slice(0, 10) + 'T09:15';
    let data; try { data = await api('/api/spot-series', { u: S.u, from: fromStr, to: S.at, interval: S.spotInterval }); } catch (e) { toast(e.message); return; }
    // lightweight-charts shows UTC; shift by IST offset so labels read as IST.
    const shift = 19800;
    S.spotSeries.setData(data.candles.map((c) => ({ time: c.time + shift, open: c.open, high: c.high, low: c.low, close: c.close })));
    S.spotSeries.setMarkers(S.legs.filter((l) => l.on).map((l) => ({ time: Math.floor(parseTs(l.entryAt).getTime() / 1000) + shift, position: l.side === 'B' ? 'belowBar' : 'aboveBar', color: l.side === 'B' ? '#22c55e' : '#ef4444', shape: l.side === 'B' ? 'arrowUp' : 'arrowDown', text: `${l.side} ${l.kind} ${fmtNum(l.strike)}` })).sort((a, b) => a.time - b.time));
    S.spotChart.timeScale().fitContent();
  }
  $('spot-interval').addEventListener('click', (ev) => { const b = ev.target.closest('button'); if (!b) return; S.spotInterval = b.dataset.i; document.querySelectorAll('#spot-interval button').forEach((x) => x.classList.toggle('active', x === b)); loadSpotChart(); });

  /* ---------- templates ---------- */
  function renderTemplates(group) {
    const groups = $('template-groups'); groups.innerHTML = '';
    window.STRATEGY_GROUPS.forEach((g) => { const b = document.createElement('button'); b.textContent = g; b.className = g === group ? 'active' : ''; b.onclick = () => renderTemplates(g); groups.appendChild(b); });
    const grid = $('template-grid'); grid.innerHTML = '';
    window.STRATEGY_TEMPLATES.filter((t) => t.group === group).forEach((t) => {
      const card = document.createElement('div'); card.className = 'template'; card.title = t.desc;
      card.innerHTML = window.Charts.templateSvg(t) + `<div class="t-name">${t.name}</div><div class="t-desc">${t.desc}</div>`;
      card.onclick = () => applyTemplate(t);
      grid.appendChild(card);
    });
  }
  async function applyTemplate(t) {
    if (!S.chain || !S.chain.rows.length) { toast('Load an option chain first'); return; }
    if (S.legs.length && !confirm(`Replace current legs with "${t.name}"?`)) return;
    const chains = { 0: S.chain };
    const needsNext = t.legs.some((l) => l.exp === 1);
    if (needsNext) {
      const i = S.snap.expiries.findIndex((e) => e.expiry === S.chainExpiry);
      const next = S.snap.expiries[i + 1];
      if (!next) { toast('This template needs a second expiry, none available at this time'); return; }
      try { chains[1] = await api('/api/chain', { u: S.u, at: S.at, expiry: next.expiry }); } catch (e) { toast(e.message); return; }
    }
    const legs = [];
    for (const tl of t.legs) {
      const ch = chains[tl.exp || 0];
      const atmIdx = ch.rows.findIndex((r) => r.strike === ch.atm);
      const row = ch.rows[atmIdx + tl.offset];
      const c = row && row[tl.kind];
      if (!c || c.ltp === null) { toast(`Strike ${tl.offset > 0 ? 'ATM+' : 'ATM'}${tl.offset} ${tl.kind} not available in the stored band`); return; }
      legs.push({ id: c.id, kind: tl.kind, strike: row.strike, expiry: ch.expiry, side: tl.side, lots: tl.lots || 1, entryPrice: c.ltp, entryAt: S.at, on: true, lotSize: ch.lot_size });
    }
    S.legs = legs; S.name = t.name; $('strategy-name').value = t.name;
    setView('payoff'); refreshLegs(); renderChain(); writeHash(); toast(`${t.name} loaded with ${legs.length} leg${legs.length > 1 ? 's' : ''}`);
  }

  /* ---------- views ---------- */
  function setView(v) {
    S.view = v;
    document.querySelectorAll('#view-tabs button').forEach((b) => b.classList.toggle('active', b.dataset.view === v));
    ['payoff', 'pnl', 'spot', 'templates'].forEach((x) => { $('view-' + x).hidden = x !== v; });
    if (v === 'payoff') renderPayoff();
    if (v === 'pnl') loadPnl();
    if (v === 'spot') loadSpotChart();
    if (v === 'templates') renderTemplates(S.templateGroup || 'Neutral');
  }
  $('view-tabs').addEventListener('click', (ev) => { const b = ev.target.closest('button'); if (b) setView(b.dataset.view); });

  /* ---------- time controls ---------- */
  async function step(s) {
    if (!S.snap) return;
    try { const r = await api('/api/step', { at: S.at, step: s }); const max = S.snap.range.max?.replace(' ', 'T').slice(0, 16), min = S.snap.range.min?.replace(' ', 'T').slice(0, 16); if (max && r.at > max) { toast('Reached the latest available data'); stopAutoplay(); return false; } if (min && r.at < min) { toast('Reached the earliest available data'); return false; } S.at = r.at; await load(); return true; } catch (e) { toast(e.message); return false; }
  }
  document.querySelectorAll('.step').forEach((b) => { b.onclick = () => step(b.dataset.step); });
  // Picking a different date starts that day at the market open (09:15); changing only the time keeps the date.
  $('time-input').addEventListener('change', (e) => {
    const v = e.target.value; if (!v) return;
    const dateChanged = v.slice(0, 10) !== (S.at || '').slice(0, 10);
    S.at = dateChanged ? v.slice(0, 10) + 'T09:15' : v;
    load();
  });
  function stopAutoplay() { if (S.autoplay) { clearInterval(S.autoplay); S.autoplay = null; $('autoplay-btn').textContent = '▶ Autoplay'; $('autoplay-btn').classList.remove('playing'); } }
  $('autoplay-btn').onclick = () => {
    if (S.autoplay) { stopAutoplay(); return; }
    let busy = false;
    S.autoplay = setInterval(async () => { if (busy) return; busy = true; const ok = await step($('autoplay-step').value); busy = false; if (!ok) stopAutoplay(); }, +$('autoplay-speed').value);
    $('autoplay-btn').textContent = '❚❚ Pause'; $('autoplay-btn').classList.add('playing');
  };
  $('autoplay-speed').onchange = () => { if (S.autoplay) { stopAutoplay(); $('autoplay-btn').click(); } };
  document.addEventListener('keydown', (e) => { if (['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement.tagName)) return; if (e.key === 'ArrowRight') step(e.shiftKey ? '+1h' : '+5m'); if (e.key === 'ArrowLeft') step(e.shiftKey ? '-1h' : '-5m'); if (e.key === ' ') { e.preventDefault(); $('autoplay-btn').click(); } });

  /* ---------- save & share ---------- */
  const SAVED_KEY = 'optsim.saved';
  const loadSaved = () => { try { return JSON.parse(localStorage.getItem(SAVED_KEY) || '[]'); } catch (e) { return []; } };
  const storeSaved = (list) => { try { localStorage.setItem(SAVED_KEY, JSON.stringify(list)); } catch (e) { toast('Could not save in this browser'); } $('saved-count').textContent = loadSaved().length; };
  $('save-strategy').onclick = () => {
    if (!S.legs.length) { toast('Add legs before saving'); return; }
    const name = S.name || prompt('Strategy name', 'My strategy'); if (!name) return;
    S.name = name; $('strategy-name').value = name;
    const list = loadSaved().filter((s) => s.n !== name);
    list.unshift({ ...encodeState(), n: name, savedAt: new Date().toISOString() });
    storeSaved(list.slice(0, 50)); toast(`Saved "${name}"`);
  };
  function renderSavedList() {
    const list = loadSaved(); const box = $('saved-list');
    box.innerHTML = list.length ? '' : '<p class="muted">Nothing saved yet.</p>';
    list.forEach((s, i) => {
      const item = document.createElement('div'); item.className = 'saved-item';
      item.innerHTML = `<div><div class="s-name">${s.n}</div><div class="s-meta">${s.u} · ${s.legs.length} leg${s.legs.length !== 1 ? 's' : ''} · ${fmtAt(s.at)}</div></div><div class="btn-row"><button class="btn btn-sm" data-act="open">Open</button><button class="btn btn-sm btn-ghost btn-danger" data-act="del">Delete</button></div>`;
      item.querySelector('[data-act=open]').onclick = () => { applyState(s); $('strategy-name').value = S.name; $('saved-dialog').close(); S.firstScroll = true; load(); };
      item.querySelector('[data-act=del]').onclick = () => { list.splice(i, 1); storeSaved(list); renderSavedList(); };
      box.appendChild(item);
    });
  }
  $('saved-btn').onclick = () => { renderSavedList(); $('saved-dialog').showModal(); };
  $('saved-close').onclick = () => $('saved-dialog').close();
  $('share-btn').onclick = async () => {
    writeHash();
    try { await navigator.clipboard.writeText(location.href); toast('Link copied to clipboard'); } catch (e) { prompt('Copy this link', location.href); }
  };

  /* ---------- theme ---------- */
  $('theme-toggle').onclick = () => {
    const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next; try { localStorage.setItem('theme', next); } catch (e) { /* ignore */ }
    if (S.spotChart) { S.spotChart.remove(); S.spotChart = null; S.spotSeries = null; if (S.view === 'spot') loadSpotChart(); }
    renderPayoff();
  };
  window.addEventListener('resize', () => { renderPayoff(); if (S.view === 'pnl') loadPnl(); });

  /* ---------- boot ---------- */
  async function boot() {
    try { const cfg = await api('/api/underlyings', {}); S.settings = cfg.settings; window.APP.underlyings = cfg.underlyings; } catch (e) { toast(e.message); }
    S.u = window.APP.defaultUnderlying;
    if (!underlyings().some((x) => x.symbol === S.u && x.range?.max)) { const first = underlyings().find((x) => x.range?.max); S.u = first ? first.symbol : (underlyings()[0]?.symbol || null); }
    applyState(readHash());
    $('strategy-name').value = S.name;
    $('saved-count').textContent = loadSaved().length;
    clampTime();
    if (!S.u) { $('time-label').textContent = 'No underlyings configured'; return; }
    if (!S.at) S.at = new Date().toISOString().slice(0, 16);
    renderTemplates('Neutral');
    await load();
    const u = U();
    if (!u.range || !u.range.max) toast('No historical data collected yet. Run the fetcher from the admin panel.');
  }
  boot();
})();
