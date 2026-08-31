<?= view('partials/header', ['title' => $title, 'page' => $page, 'crumbGroup' => $crumbGroup, 'crumbPage' => $crumbPage]) ?>
<div id="app" data-ref="<?= esc($ref) ?>"></div>
<?= view('partials/footer', ['jsPage' => $jsPage]) ?>
