<?php use App\Http\View; ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin · <?= View::e($siteName) ?></title>
<link rel="stylesheet" href="<?= View::asset('assets/css/app.css') ?>">
<link rel="icon" href="<?= View::asset('assets/img/favicon.svg') ?>" type="image/svg+xml">
</head>
<body class="admin">
<aside class="admin-nav">
  <a class="brand" href="/"><img src="<?= View::asset('assets/img/logo.svg') ?>" alt="" width="28" height="28"><span><?= View::e($siteName) ?></span></a>
  <nav>
    <?php foreach (['admin/dashboard' => ['/admin', 'Dashboard'], 'admin/jobs' => ['/admin/jobs', 'Data jobs'], 'admin/console' => ['/admin/console', 'Console'], 'admin/underlyings' => ['/admin/underlyings', 'Underlyings'], 'admin/holidays' => ['/admin/holidays', 'Holidays'], 'admin/settings' => ['/admin/settings', 'Settings'], 'admin/account' => ['/admin/account', 'Account']] as $key => [$href, $label]): ?>
      <a href="<?= $href ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </nav>
  <form method="post" action="/admin/logout" class="nav-footer"><input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>"><span class="muted small"><?= View::e($user['email']) ?></span><button class="btn btn-ghost btn-sm" type="submit">Sign out</button></form>
</aside>
<main class="admin-main"><?= $content ?></main>
</body>
</html>
