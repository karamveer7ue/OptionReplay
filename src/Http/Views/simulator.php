<?php use App\Http\View; ?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= View::e($siteName) ?> · Options strategy simulator</title>
<meta name="description" content="Build an options strategy on NIFTY, BANKNIFTY or SENSEX and replay it against real historical prices, minute by minute.">
<link rel="icon" href="<?= View::asset('assets/img/favicon.svg') ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= View::asset('assets/css/app.css') ?>">
<script>try{var t=localStorage.getItem('theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
</head>
<body class="sim">
<header class="topbar">
  <a class="brand" href="/"><img src="<?= View::asset('assets/img/logo.svg') ?>" alt="" width="28" height="28"><span><?= View::e($siteName) ?></span><small>Simulator</small></a>
  <div class="underlying-switch" id="underlying-switch" role="tablist"></div>
  <div class="topbar-right">
    <button class="btn btn-ghost btn-icon" id="theme-toggle" title="Toggle theme" aria-label="Toggle theme"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg></button>
    <button class="btn btn-ghost" id="saved-btn">Saved <span class="count" id="saved-count">0</span></button>
    <button class="btn btn-ghost" id="share-btn">Share</button>
  </div>
</header>

<section class="timebar" id="timebar">
  <div class="time-steps">
    <button class="step" data-step="-1d">−1d</button>
    <button class="step" data-step="sod">SOD</button>
    <button class="step" data-step="-1h">−1h</button>
    <button class="step" data-step="-15m">−15m</button>
    <button class="step" data-step="-5m">−5m</button>
  </div>
  <div class="time-now">
    <input type="datetime-local" id="time-input" step="300">
    <div class="time-label" id="time-label">Loading…</div>
  </div>
  <div class="time-steps">
    <button class="step" data-step="+5m">+5m</button>
    <button class="step" data-step="+15m">+15m</button>
    <button class="step" data-step="+1h">+1h</button>
    <button class="step" data-step="eod">EOD</button>
    <button class="step" data-step="+1d">+1d</button>
  </div>
  <div class="autoplay">
    <button class="btn" id="autoplay-btn" title="Autoplay">▶ Autoplay</button>
    <select id="autoplay-step"><option value="+5m">+5m</option><option value="+15m">+15m</option><option value="+1h">+1h</option><option value="+1d">+1d</option></select>
    <select id="autoplay-speed"><option value="2000">every 2s</option><option value="1000" selected>every 1s</option><option value="500">every 0.5s</option></select>
  </div>
</section>

<main class="workspace">
  <section class="panel chain-panel">
    <div class="market-strip" id="market-strip">
      <div class="mkt"><span class="mkt-label">Spot</span><span class="mkt-value" id="spot-value">–</span><span class="mkt-change" id="spot-change"></span></div>
      <div class="mkt"><span class="mkt-label">Future <small id="fut-expiry"></small></span><span class="mkt-value" id="fut-value">–</span><span class="mkt-note" id="fut-note"></span></div>
      <div class="mkt"><span class="mkt-label">Lot</span><span class="mkt-value" id="lot-value">–</span></div>
      <div class="mkt"><span class="mkt-label">1σ move <small>weekly · monthly</small></span><span class="mkt-value mkt-sd" id="sd-value">–</span><span class="mkt-note" id="sd-note"></span></div>
      <div class="mkt mkt-right"><span class="mkt-label">Data</span><span class="mkt-note" id="data-range">–</span></div>
    </div>
    <div class="expiry-tabs" id="expiry-tabs"></div>
    <div class="chain-tools">
      <label class="check small"><input type="checkbox" id="chain-greeks"> Show Greeks</label>
      <label class="check small"><input type="checkbox" id="chain-oi" checked> OI bars</label>
      <span class="muted small" id="chain-hint">Click a premium to add a leg: left column buys/sells calls, right column puts.</span>
    </div>
    <div class="chain-scroll" id="chain-scroll">
      <table class="chain" id="chain-table">
        <thead><tr id="chain-head"></tr></thead>
        <tbody id="chain-body"><tr><td class="muted center" colspan="9">Select an underlying and time to load the option chain.</td></tr></tbody>
      </table>
    </div>
  </section>

  <div class="splitter" id="splitter" role="separator" aria-orientation="vertical" title="Drag to resize. Double-click to reset." tabindex="0"></div>

  <section class="panel strategy-panel">
    <div class="tabs view-tabs" id="view-tabs">
      <button class="active" data-view="payoff">Payoff</button>
      <button data-view="pnl">P&amp;L over time</button>
      <button data-view="spot">Spot chart</button>
      <button data-view="templates">Ready-made strategies</button>
    </div>
    <div class="view" id="view-payoff">
      <div class="payoff-head">
        <div class="metrics" id="metrics"></div>
        <div class="payoff-controls">
          <label class="check small"><input type="checkbox" id="payoff-t0" checked> T+0 curve</label>
          <label class="check small"><input type="checkbox" id="charges-toggle"> Include charges</label>
          <label class="check small" title="Expected move to the weekly expiry: ±1σ and ±2σ from ATM implied volatility"><input type="checkbox" id="sd-weekly" checked> σ weekly</label>
          <label class="check small" title="Expected move to the monthly expiry: ±1σ and ±2σ from ATM implied volatility"><input type="checkbox" id="sd-monthly" checked> σ monthly</label>
          <label class="small">Range ±<input type="number" id="payoff-range" value="8" min="2" max="40" step="1" class="tiny">%</label>
          <button class="btn btn-sm" id="payoff-reset" hidden title="Double-click the chart also resets">Reset zoom</button>
          <span class="muted small zoom-hint">Drag on the chart to zoom</span>
        </div>
      </div>
      <div class="chart-wrap"><svg id="payoff-chart" class="chart" role="img" aria-label="Payoff chart"></svg><div class="chart-tip" id="payoff-tip" hidden></div></div>
    </div>
    <div class="view" id="view-pnl" hidden>
      <div class="pnl-head"><div class="metrics" id="pnl-metrics"></div><div class="payoff-controls"><button class="btn btn-sm" id="pnl-reset" hidden title="Double-click the chart also resets">Reset zoom</button><span class="muted small">Mark-to-market since the first leg was added. Drag on the chart to zoom.</span></div></div>
      <div class="chart-wrap"><svg id="pnl-chart" class="chart" role="img" aria-label="P&amp;L over time chart"></svg><div class="chart-tip" id="pnl-tip" hidden></div></div>
    </div>
    <div class="view" id="view-spot" hidden>
      <div class="spot-head">
        <div class="tabs small-tabs" id="spot-interval"><button data-i="1m">1m</button><button data-i="5m" class="active">5m</button><button data-i="15m">15m</button><button data-i="1h">1h</button><button data-i="1d">1D</button></div>
        <span class="muted small">Candles up to the current simulation time. Leg entries are marked; dashed lines are ±1σ and ±2σ expected moves (W weekly, M monthly).</span>
      </div>
      <div id="spot-chart" class="tv-chart"></div>
    </div>
    <div class="view" id="view-templates" hidden>
      <div class="tabs small-tabs" id="template-groups"></div>
      <div class="template-grid" id="template-grid"></div>
    </div>

    <div class="legs">
      <div class="legs-head">
        <h2>Strategy legs <span class="count" id="legs-count">0</span></h2>
        <div class="btn-row">
          <input class="name-input" id="strategy-name" placeholder="Untitled strategy" maxlength="60">
          <button class="btn btn-sm" id="save-strategy">Save</button>
          <button class="btn btn-sm btn-ghost" id="reset-legs">Clear</button>
        </div>
      </div>
      <div class="table-wrap"><table class="table legs-table" id="legs-table">
        <thead><tr><th></th><th>Side</th><th>Expiry</th><th>Strike</th><th>Type</th><th>Lots</th><th>Entry</th><th>Now / Exit</th><th>P&amp;L</th><th>IV</th><th>Δ</th><th>Θ</th><th></th></tr></thead>
        <tbody id="legs-body"><tr><td colspan="13" class="muted center">No legs yet. Click a premium in the chain or pick a ready-made strategy. Use Close on a leg to book its profit or loss at any time, and keep adding legs as the replay goes on.</td></tr></tbody>
      </table></div>
      <div class="legs-foot" id="legs-foot"></div>
    </div>
  </section>
</main>

<dialog id="saved-dialog" class="dialog">
  <div class="dialog-head"><h2>Saved strategies</h2><button class="btn btn-ghost btn-sm" id="saved-close">Close</button></div>
  <div id="saved-list" class="saved-list"></div>
  <p class="muted small">Saved in this browser only. Use Share to get a link you can open anywhere.</p>
</dialog>
<div class="toast" id="toast" hidden></div>

<script>
window.APP = <?= json_encode(['underlyings' => $underlyings, 'defaultUnderlying' => $defaultUnderlying, 'siteName' => $siteName], JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= View::asset('assets/js/vendor/lightweight-charts.js') ?>"></script>
<script src="<?= View::asset('assets/js/strategies.js') ?>"></script>
<script src="<?= View::asset('assets/js/charts.js') ?>"></script>
<script src="<?= View::asset('assets/js/app.js') ?>"></script>
</body>
</html>
