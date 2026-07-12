<?php
/**
 * pricing.php - edit package prices. Writes settings key price_<pkg>, which the
 * bot reads live (same values the in-bot /promoadmin edits).
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();

$flash = null; $flashType = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hl_csrf_check();
    $saved = 0;
    foreach (HL_PACKAGES as $key => $meta) {
        if (!isset($_POST['price_' . $key])) continue;
        $raw = trim($_POST['price_' . $key]);
        if ($raw === '' || !is_numeric($raw) || $raw < 0) continue;
        $val = ($raw == (int) $raw) ? (string) (int) $raw : (string) (0 + $raw);
        hl_set_setting('price_' . $key, $val);
        $saved++;
    }
    $flash = "Saved $saved package price(s). The bot uses the new prices on its next message.";
}

$prices = [];
foreach (HL_PACKAGES as $key => $meta) {
    $prices[$key] = hl_get_setting('price_' . $key, (string) $meta['default']);
}

$csrf = h(hl_csrf_token());
hl_shell_head('Plan & Pricing', 'pricing', hl_pending_count());
if ($flash) hl_flash($flash, $flashType);
?>

<div class="card">
  <h2>Package prices</h2>
  <p class="sub">Change a price and the bot uses it immediately - no restart. These are the same values the in-bot /promoadmin command edits.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <?php foreach (HL_PACKAGES as $key => $meta): ?>
      <div class="row">
        <div class="field">
          <label><?= h($meta['name']) ?> <span class="muted small">&mdash; <?= h($meta['note']) ?></span></label>
          <div class="prefix"><span class="sym">$</span>
            <input type="number" name="price_<?= h($key) ?>" min="0" step="0.01" value="<?= h($prices[$key]) ?>">
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    <button type="submit">Save prices</button>
  </form>
</div>

<?php hl_shell_foot();
