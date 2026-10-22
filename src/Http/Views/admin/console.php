<?php use App\Http\View; ?>
<header class="page-head"><h1>Console</h1><div><?= $lockHeld ? '<span class="tag tag-green">fetcher running</span>' : '<span class="tag">fetcher idle</span>' ?> <span class="muted small">pending <?= number_format($counts['pending']) ?> · failed <?= number_format($counts['failed']) ?></span></div></header>
<section class="card">
  <p class="muted">Run any of the cron commands once, right here, and watch the output as it happens. Each button runs the same code as <code>php bin/fetch.php --&lt;command&gt;</code>.</p>
  <form method="post" action="/admin/console/run" target="console-output" class="console-form" id="console-form">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <div class="form-grid">
      <label>Underlying<select name="underlying"><option value="">all enabled</option><?php foreach ($underlyings as $u): ?><option value="<?= View::e($u['symbol']) ?>"><?= View::e($u['symbol']) ?></option><?php endforeach; ?></select></label>
      <label>Run budget (seconds)<input type="number" name="budget" min="5" max="600" value="<?= (int) $budget ?>"><small>Only for "run". The page streams until the budget is spent.</small></label>
      <label>As of date<input type="date" name="today" value="<?= date('Y-m-d') ?>"><small>Planning date, normally today.</small></label>
    </div>
    <div class="btn-row console-buttons">
      <?php foreach ($commands as $key => $desc): ?>
        <button class="btn <?= $key === 'run' ? 'btn-primary' : '' ?>" type="submit" name="command" value="<?= $key ?>" title="<?= View::e($desc) ?>" <?= ($key === 'plan-dhan' && !$dhan) ? 'disabled title="Add DHAN_CLIENT_ID and DHAN_ACCESS_TOKEN to .env"' : '' ?>><?= $key === 'run' ? 'run (process jobs)' : $key ?></button>
      <?php endforeach; ?>
    </div>
    <dl class="cmd-help">
      <?php foreach ($commands as $key => $desc): ?><div><dt><code>--<?= $key ?></code></dt><dd><?= View::e($desc) ?></dd></div><?php endforeach; ?>
    </dl>
  </form>
</section>
<section class="card console-card">
  <div class="console-head"><h2>Output</h2><button class="btn btn-sm btn-ghost" type="button" onclick="document.getElementById('console-output').src='about:blank'">Clear</button></div>
  <iframe id="console-output" name="console-output" class="console-frame" title="Command output" src="about:blank"></iframe>
</section>
