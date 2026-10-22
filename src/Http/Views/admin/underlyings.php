<?php use App\Http\View; ?>
<header class="page-head"><h1>Underlyings</h1></header>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-ok">Saved. Queue a backfill under Data jobs to start fetching.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-ok">Underlying and its data removed.</div><?php endif; ?>
<section class="card">
  <table class="table">
    <thead><tr><th>Symbol</th><th>Name</th><th>Kind</th><th>F&amp;O exch</th><th>Spot neosymbol</th><th>Lot</th><th>Step</th><th>Overrides</th><th>Enabled</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><?= View::e($r['symbol']) ?></strong></td><td><?= View::e($r['name']) ?></td><td><?= View::e($r['kind']) ?></td><td><?= View::e($r['fo_exchange']) ?></td><td><code><?= View::e($r['spot_neosymbol']) ?></code></td>
        <td><?= (int) $r['lot_size'] ?></td><td><?= (float) $r['strike_step'] ?></td>
        <td class="small muted"><?= $r['lookback_days'] ? "lookback {$r['lookback_days']}d " : '' ?><?= $r['strike_band'] ? "band ±{$r['strike_band']} " : '' ?><?= $r['expiry_window_days'] ? "expiries {$r['expiry_window_days']}d" : '' ?></td>
        <td><?= $r['enabled'] ? '<span class="tag tag-green">yes</span>' : '<span class="tag">no</span>' ?></td>
        <td class="nowrap">
          <button class="btn btn-sm" type="button" onclick='fillForm(<?= json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>
          <form method="post" action="/admin/underlyings/<?= (int) $r['id'] ?>/delete" style="display:inline" onsubmit="return confirm('Delete <?= View::e($r['symbol']) ?> and ALL its stored candles?')"><input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>"><button class="btn btn-sm btn-danger" type="submit">Delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<section class="card">
  <h2 id="form-title">Add underlying</h2>
  <p class="muted small">Symbol must match Kotak's <code>pSymbolName</code> (e.g. NIFTY, BANKNIFTY, SENSEX, RELIANCE). Spot neosymbol is <code>segment|token</code> from Kotak's scrip master, e.g. <code>nse_cm|Nifty 50</code>, <code>nse_cm|Nifty Bank</code>, <code>bse_cm|SENSEX</code>, or for a stock the NSE token like <code>nse_cm|2885</code>.</p>
  <form method="post" action="/admin/underlyings" class="form-grid" id="u-form">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>"><input type="hidden" name="id" value="">
    <label>Symbol<input name="symbol" required pattern="[A-Za-z0-9&\-]{2,32}"></label>
    <label>Display name<input name="name" required></label>
    <label>Kind<select name="kind"><option value="index">Index</option><option value="stock">Stock</option></select></label>
    <label>F&amp;O exchange<select name="fo_exchange"><option value="nse_fo">nse_fo</option><option value="bse_fo">bse_fo</option></select></label>
    <label>Spot neosymbol<input name="spot_neosymbol" required placeholder="nse_cm|Nifty 50"></label>
    <label>Lot size (auto-refreshed)<input name="lot_size" type="number" min="0" value="0"></label>
    <label>Strike step (auto-refreshed)<input name="strike_step" type="number" step="0.05" min="0.05" value="50"></label>
    <label>Sort order<input name="sort_order" type="number" value="0"></label>
    <label>Lookback days override<input name="lookback_days" type="number" min="1" placeholder="global"></label>
    <label>Strike band override (± strikes)<input name="strike_band" type="number" min="1" placeholder="global"></label>
    <label>Expiry window days override<input name="expiry_window_days" type="number" min="1" placeholder="global"></label>
    <label>Weekly expiry weekday<select name="weekly_expiry_dow"><option value="">none</option><option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option><option value="4">Thursday</option><option value="5">Friday</option></select></label>
    <label>Monthly expiry weekday<select name="monthly_expiry_dow"><option value="1">Monday</option><option value="2">Tuesday</option><option value="3">Wednesday</option><option value="4" selected>Thursday</option><option value="5">Friday</option></select></label>
    <label>Dhan segment (backfill)<select name="dhan_segment"><option value="">not set</option><option value="NSE_FNO">NSE_FNO</option><option value="BSE_FNO">BSE_FNO</option></select></label>
    <label>Dhan security id (underlying)<input name="dhan_security_id" type="number" min="1" placeholder="e.g. 13 for NIFTY"></label>
    <label class="check"><input type="checkbox" name="enabled" value="1" checked> Enabled</label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save</button> <button class="btn" type="button" onclick="resetForm()">Clear</button></div>
  </form>
</section>
<script>
function fillForm(r){const f=document.getElementById('u-form');for(const k in r){const el=f.elements[k];if(!el)continue;if(el.type==='checkbox'){el.checked=!!+r[k];}else{el.value=r[k]??'';}}document.getElementById('form-title').textContent='Edit '+r.symbol;f.scrollIntoView({behavior:'smooth'});}
function resetForm(){const f=document.getElementById('u-form');f.reset();f.elements.id.value='';document.getElementById('form-title').textContent='Add underlying';}
</script>
