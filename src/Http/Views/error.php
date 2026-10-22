<?php use App\Http\View; ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Error</title>
<link rel="stylesheet" href="<?= View::asset('assets/css/app.css') ?>"></head>
<body class="center-page"><div class="card" style="max-width:480px"><h1 style="margin-top:0">Something went wrong</h1><p class="muted"><?= View::e($message) ?></p><a class="btn" href="/">Back to simulator</a></div></body></html>
