<?php use App\Http\View; ?>
<header class="page-head"><h1>Trading holidays</h1></header>
<section class="card">
  <form method="post" action="/admin/holidays" class="inline-form"><input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <label>Date<input type="date" name="day" required></label><label>Description<input name="description" placeholder="e.g. Diwali"></label><button class="btn btn-primary" type="submit">Add</button>
  </form>
  <p class="muted small">Weekdays not listed here are treated as trading days by the time stepper.</p>
</section>
<section class="card"><table class="table"><thead><tr><th>Date</th><th>Day</th><th>Description</th><th>Source</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= View::e($r['day']) ?></td><td><?= date('D', strtotime($r['day'])) ?></td><td><?= View::e($r['description']) ?></td><td class="muted"><?= View::e($r['source']) ?></td>
<td><form method="post" action="/admin/holidays/<?= View::e($r['day']) ?>/delete"><input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>"><button class="btn btn-sm btn-danger" type="submit">Remove</button></form></td></tr><?php endforeach; ?>
</tbody></table></section>
