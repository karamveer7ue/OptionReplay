<?php use App\Http\View; $v = $values; ?>
<header class="page-head"><h1>Settings</h1></header>
<?php if ($saved): ?><div class="alert alert-ok">Settings saved.</div><?php endif; ?>
<form method="post" action="/admin/settings">
<input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
<section class="card"><h2>Data collection</h2>
  <div class="form-grid">
    <label>Lookback period (days)<input name="lookback_days" type="number" min="1" value="<?= View::e($v['lookback_days']) ?>"><small>How far back to fetch. Kotak only serves live contracts; expired contracts come from the Dhan backfill.</small></label>
    <label>Strike band (± strikes around ATM)<input name="strike_band" type="number" min="1" value="<?= View::e($v['strike_band']) ?>"></label>
    <label>Expiry window (days ahead)<input name="expiry_window_days" type="number" min="1" value="<?= View::e($v['expiry_window_days']) ?>"></label>
    <label>Delay between requests (ms)<input name="fetch_delay_ms" type="number" min="500" step="100" value="<?= View::e($v['fetch_delay_ms']) ?>"><small>Kotak throttles fast clients. 2000 to 3000 ms is safe.</small></label>
    <label>Time budget per run (seconds)<input name="fetch_time_budget_sec" type="number" min="10" max="3600" value="<?= View::e($v['fetch_time_budget_sec']) ?>"><small>Each cron tick processes jobs for this long, then exits.</small></label>
  </div>
</section>
<section class="card"><h2>Pricing</h2>
  <div class="form-grid">
    <label>Risk-free rate (decimal)<input name="risk_free_rate" type="number" step="0.0001" min="0" value="<?= View::e($v['risk_free_rate']) ?>"><small>Used for implied volatility, Greeks and the synthetic future.</small></label>
  </div>
</section>
<section class="card"><h2>Charges (optional in the simulator)</h2>
  <div class="form-grid">
    <label>Brokerage per order (₹)<input name="brokerage_per_order" type="number" step="0.01" min="0" value="<?= View::e($v['brokerage_per_order']) ?>"></label>
    <label>STT on sell premium (%)<input name="stt_sell_pct" type="number" step="0.0001" min="0" value="<?= View::e($v['stt_sell_pct']) ?>"></label>
    <label>Exchange transaction (%)<input name="exchange_txn_pct" type="number" step="0.00001" min="0" value="<?= View::e($v['exchange_txn_pct']) ?>"></label>
    <label>SEBI fee (%)<input name="sebi_fee_pct" type="number" step="0.00001" min="0" value="<?= View::e($v['sebi_fee_pct']) ?>"></label>
    <label>Stamp duty on buy (%)<input name="stamp_duty_buy_pct" type="number" step="0.0001" min="0" value="<?= View::e($v['stamp_duty_buy_pct']) ?>"></label>
    <label>GST on brokerage + fees (%)<input name="gst_pct" type="number" step="0.01" min="0" value="<?= View::e($v['gst_pct']) ?>"></label>
  </div>
</section>
<section class="card"><h2>Site</h2>
  <div class="form-grid">
    <label>Site name<input name="site_name" value="<?= View::e($v['site_name']) ?>"></label>
    <label>Default underlying<input name="default_underlying" value="<?= View::e($v['default_underlying']) ?>"></label>
  </div>
</section>
<div class="form-actions"><button class="btn btn-primary" type="submit">Save settings</button></div>
</form>
