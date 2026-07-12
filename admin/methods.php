<?php
/**
 * methods.php - edit payment handles (Zelle / Cash App / Support). These show
 * on the bot's manual payment screen the moment they are saved.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();

$flash = null; $flashType = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hl_csrf_check();
    foreach (HL_PAY_HANDLES as $key => $meta) {
        if (!isset($_POST[$key])) continue;
        hl_set_setting($key, trim($_POST[$key]));
    }
    $flash = 'Payment handles saved. They show in the bot payment screen instantly.';
}

$handles = [];
foreach (HL_PAY_HANDLES as $key => $meta) {
    $handles[$key] = hl_get_setting($key, $key === 'pay_support' ? '@Habesha_list' : '');
}

$csrf = h(hl_csrf_token());
hl_shell_head('Payment Methods', 'methods', hl_pending_count());
if ($flash) hl_flash($flash, $flashType);
?>

<div class="card">
  <h2>Payment handles</h2>
  <p class="sub">Shown to customers on the manual payment screen ("Please send payment to: ..."). Leave one blank to hide that method and fall back to "contact support".</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <?php foreach (HL_PAY_HANDLES as $key => $meta): ?>
      <div class="row">
        <div class="field">
          <label><?= h($meta['label']) ?></label>
          <input type="text" name="<?= h($key) ?>" value="<?= h($handles[$key]) ?>" placeholder="<?= h($meta['placeholder']) ?>">
        </div>
      </div>
    <?php endforeach; ?>
    <button type="submit">Save handles</button>
  </form>
</div>

<?php hl_shell_foot();
