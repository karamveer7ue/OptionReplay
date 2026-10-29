<?php use App\Http\View; ?>
<header class="page-head"><h1>Dashboard</h1><div class="muted">Database <?= View::e($dbSize['mb'] ?? 0) ?> MB · Dhan backfill: <?= $dhan ? '<span class="tag tag-green">configured</span>' : '<span class="tag">not configured</span>' ?></div></header>
<section class="stat-row">
  <?php foreach (['pending' => 'Pending', 'running' => 'Running', 'done' => 'Done', 'empty' => 'Empty', 'failed' => 'Failed'] as $k => $label): ?>
    <a class="stat" href="/admin/jobs?status=<?= $k ?>"><span class="stat-label"><?= $label ?> jobs</span><span class="stat-value <?= $k === 'failed' && $jobs[$k] > 0 ? 'neg' : '' ?>"><?= number_format($jobs[$k]) ?></span></a>
  <?php endforeach; ?>
</section>
<section class="card">
  <h2>Data coverage</h2>
  <table class="table">
    <thead><tr><th>Underlying</th><th>Spot 1-min</th><th>Spot range</th><th>Contracts (live)</th><th>Option 5-min rows</th><th>Option range</th><th>Jobs pending / failed</th></tr></thead>
    <tbody>
    <?php foreach ($underlyings as $u): ?>
      <tr>
        <td><strong><?= View::e($u['symbol']) ?></strong> <?= $u['enabled'] ? '' : '<span class="tag">disabled</span>' ?><div class="muted small">lot <?= (int) $u['lot_size'] ?> · step <?= (float) $u['strike_step'] ?></div></td>
        <td><?= number_format((int) ($u['spot_range']['n'] ?? 0)) ?></td>
        <td class="small"><?= View::e($u['spot_range']['mn'] ?? '-') ?><br><?= View::e($u['spot_range']['mx'] ?? '-') ?></td>
        <td><?= number_format($u['contracts']) ?> (<?= number_format($u['live_contracts']) ?>)</td>
        <td><?= number_format($u['option_rows']) ?></td>
        <td class="small"><?= View::e($u['option_range']['mn'] ?? '-') ?><br><?= View::e($u['option_range']['mx'] ?? '-') ?></td>
        <td><?= $u['jobs']['pending'] ?> / <span class="<?= $u['jobs']['failed'] ? 'neg' : '' ?>"><?= $u['jobs']['failed'] ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<section class="card">
  <h2>Recent fetch runs</h2>
  <?php if (!$runs): ?><p class="muted">No runs yet. Queue jobs under <a href="/admin/jobs">Data jobs</a>, then run <code>php bin/fetch.php --run</code> from cron or use the Run button.</p><?php else: ?>
  <table class="table"><thead><tr><th>Started</th><th>Finished</th><th>Trigger</th><th>Jobs ok</th><th>Failed</th><th>Requests</th><th>Rows</th></tr></thead><tbody>
  <?php foreach ($runs as $r): ?><tr><td><?= View::e($r['started_at']) ?></td><td><?= View::e($r['finished_at'] ?? 'running') ?></td><td><?= View::e($r['trigger_type']) ?></td><td><?= $r['jobs_done'] ?></td><td class="<?= $r['jobs_failed'] ? 'neg' : '' ?>"><?= $r['jobs_failed'] ?></td><td><?= $r['requests'] ?></td><td><?= number_format((int) $r['rows_written']) ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</section>
<section class="card">
  <h2>Cron setup</h2>
  <p class="muted">Add these to the host's cron. The runner exits when its time budget is spent and resumes on the next tick, so overlapping runs are safe.</p>
  <pre><code>* * * * *   cd /path/to/app && php bin/fetch.php --run --quiet
30 16 * * 1-5  cd /path/to/app && php bin/fetch.php --refresh --quiet</code></pre>
</section>
