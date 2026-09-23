<?php
/** @var string $jsPage */
?>
    </div>
  </main>
</div>
<div id="toast" class="toast"></div>
<script src="<?= asset_url('/assets/js/ui.js') ?>"></script>
<script src="<?= asset_url('/assets/js/select.js') ?>"></script>
<script src="<?= asset_url('/assets/js/shell.js') ?>"></script>
<script src="<?= asset_url('/assets/js/raise.js') ?>"></script>
<script src="<?= esc(asset_url('/assets/js/pages/' . $jsPage . '.js')) ?>"></script>
</body>
</html>
