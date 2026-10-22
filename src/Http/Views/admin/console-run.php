<?php use App\Http\View; if ($head): ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Running <?= View::e($label) ?></title>
<link rel="stylesheet" href="<?= View::asset('assets/css/app.css') ?>">
<script>try{var t=localStorage.getItem('theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
</head><body class="console-body">
<div class="line head">$ php bin/fetch.php --<?= View::e($label) ?></div>
<script>setInterval(function(){window.scrollTo(0,document.body.scrollHeight);},300);</script>
<?php else: ?>
<div class="line head">finished</div>
<script>window.scrollTo(0,document.body.scrollHeight);</script>
</body></html>
<?php endif; ?>
