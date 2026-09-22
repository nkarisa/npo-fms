<?= view('partials/header', ['title' => $title, 'page' => $page, 'crumbGroup' => $crumbGroup, 'crumbPage' => $crumbPage]) ?>
<div id="app" data-open="<?= esc($openRef ?? '') ?>"></div>
<?= view('partials/footer', ['jsPage' => $jsPage]) ?>
