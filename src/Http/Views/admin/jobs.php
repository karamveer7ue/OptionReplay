<?php use App\Http\View; ?>
<header class="page-head"><h1>Data jobs</h1><div><?= $lockHeld ? '<span class="tag tag-green">fetcher running</span>' : '<span class="tag">fetcher idle</span>' ?></div></header>
<?php if ($flash): ?><div class="alert alert-ok"><?= View::e($flash) ?></div><?php endif; ?>
<section class="card">
  <div class="btn-row">
    <form method="post" action="/admin/jobs/plan"><input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>"><button class="btn btn-primary" type="submit">Queue backfill</button></form>
    <form method="post" action="/admin/jobs/plan-dhan"><input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>"><button class="btn" type="submit" <?= $dhan ? '' : 'disabled title="Add DHAN_CLIENT_ID and DHAN_ACCESS_TOKEN to .env"' ?>>Queue Dhan backfill</button></form>
    <form method="post" action="/admin/jobs/refresh"><input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>"><button class="btn" type="submit">Queue today's refresh</button></form>
    <form method="post" action="/admin/jobs/run"><input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>"><button class="btn" type="submit" <?= $lockHeld ? 'disabled' : '' ?>>Run for 60s now</button></form>
    <form method="post" action="/admin/jobs/retry-failed"><input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>"><button class="btn" type="submit">Retry failed</button></form>
    <form method="post" action="/admin/jobs/clear" onsubmit="return confirm('Remove finished job records?')"><input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>"><button class="btn btn-ghost" type="submit">Clear finished</button></form>
  </div>
  <p class="muted small">Backfill = spot history for the lookback period plus discovery of live contracts and their candles. Refresh = today's candles for all live contracts (run after market close). Jobs are idempotent: queuing twice never duplicates work.</p>
</section>
<section class="card">
  <div class="tabs">
    <a href="/admin/jobs" class="<?= $status ? '' : 'active' ?>">All</a>
    <?php foreach ($counts as $k => $n): ?><a href="/admin/jobs?status=<?= $k ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= ucfirst($k) ?> <span class="count"><?= number_format($n) ?></span></a><?php endforeach; ?>
  </div>
  <table class="table small">
    <thead><tr><th>#</th><th>Kind</th><th>Underlying</th><th>Instrument</th><th>Range</th><th>Priority</th><th>Status</th><th>Attempts</th><th>Rows</th><th>Last error</th><th>Updated</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $j): ?>
      <tr>
        <td><?= $j['id'] ?></td><td><code><?= View::e($j['kind']) ?></code></td><td><?= View::e($j['symbol']) ?></td><td><?= View::e($j['trading_symbol'] ?? '-') ?></td>
        <td class="nowrap"><?= View::e($j['from_date']) ?> → <?= View::e($j['to_date']) ?></td><td><?= $j['priority'] ?></td>
        <td><span class="tag tag-<?= View::e($j['status']) ?>"><?= View::e($j['status']) ?></span><?= $j['not_before'] && $j['status'] === 'pending' ? '<div class="muted">after ' . View::e($j['not_before']) . '</div>' : '' ?></td>
        <td><?= $j['attempts'] ?></td><td><?= number_format((int) $j['rows_written']) ?></td><td class="error-cell"><?= View::e($j['last_error'] ?? '') ?></td><td class="nowrap"><?= View::e($j['updated_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="11" class="muted">No jobs.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>
