<?php use App\Http\View; ?>
<header class="page-head"><h1>Account</h1></header>
<?php if ($saved): ?><div class="alert alert-ok">Password updated.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= View::e($error) ?></div><?php endif; ?>
<section class="card" style="max-width:480px">
  <p class="muted">Signed in as <strong><?= View::e($user['email']) ?></strong></p>
  <form method="post" action="/admin/account"><input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
    <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
    <label>New password (min 10 chars)<input type="password" name="new_password" required minlength="10" autocomplete="new-password"></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Change password</button></div>
  </form>
</section>
