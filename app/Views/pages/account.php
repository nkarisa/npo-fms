<?= view('partials/header', ['title' => $title, 'page' => $page, 'crumbGroup' => $crumbGroup, 'crumbPage' => $crumbPage]) ?>
<div id="app"></div>
<script src="<?= asset_url('/assets/js/vendor/qrcode.js') ?>"></script>
<script src="<?= asset_url('/assets/js/mfa.js') ?>"></script>
<?= view('partials/footer', ['jsPage' => $jsPage]) ?>
