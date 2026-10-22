<?php use App\Http\View; ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin sign in · <?= View::e($siteName) ?></title>
<link rel="stylesheet" href="<?= View::asset('assets/css/app.css') ?>"></head>
<body class="center-page">
<form method="post" action="/admin/login" class="card login-card">
  <div class="brand brand-lg"><img src="<?= View::asset('assets/img/logo.svg') ?>" alt="" width="36" height="36"><span><?= View::e($siteName) ?></span></div>
  <h1>Admin sign in</h1>
  <?php if ($error): ?><div class="alert alert-error"><?= View::e($error) ?></div><?php endif; ?>
  <label>Email<input type="email" name="email" required autofocus autocomplete="username"></label>
  <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
  <button class="btn btn-primary btn-block" type="submit">Sign in</button>
</form>
</body></html>
