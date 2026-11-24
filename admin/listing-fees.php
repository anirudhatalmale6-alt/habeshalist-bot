<?php
/**
 * listing-fees.php - Admin > Listing Fees: which classified categories cost a
 * posting fee when the ad is posted through the bot, and how much.
 *
 * Writes the listing_fees table + the lf_enabled setting that the bot reads
 * live (includes/listing_fees.php), so a change applies to the next ad.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();

// Locate the bot's module (panel inside or beside the bot folder).
$__lf_lib = '';
foreach ([
    (function_exists('hl_bot_root') && hl_bot_root() !== '') ? hl_bot_root() : null,
    __DIR__ . '/..',
    __DIR__ . '/../bot',
    __DIR__ . '/../../bot',
] as $__root) {
    if ($__root && is_file($__root . '/includes/listing_fees.php')) { $__lf_lib = $__root . '/includes/listing_fees.php'; break; }
}
// The category list comes from the bot itself (it saves a copy in the shared
// settings table each time it runs) - the panel never loads the bot's config.
$categories = json_decode((string) hl_get_setting('lf_categories', ''), true);
if ($__lf_lib === '' || !is_array($categories) || !$categories) {
    hl_shell_head('Listing Fees', 'listingfees', hl_pending_count());
    echo '<div class="card"><h2>Listing Fees</h2><p class="muted">';
    echo $__lf_lib === ''
        ? 'The file <code>includes/listing_fees.php</code> was not found next to the bot. Upload it into the bot folder (the folder with <code>webhook.php</code>), then reload this page.'
        : 'Almost ready: the bot has not loaded the new files yet. Upload <code>webhook.php</code> and <code>includes/listing_fees.php</code>, wait a minute (or send /start to the bot), then reload this page.';
    echo '</p></div>';
    hl_shell_foot();
    exit;
}
require_once $__lf_lib;
hl_lf_ensure_schema(hl_db());

$flash = null; $flashType = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hl_csrf_check();
    hl_set_setting('lf_enabled', isset($_POST['lf_enabled']) ? '1' : '0');
    $problems = [];
    $posted = is_array($_POST['rule'] ?? null) ? $_POST['rule'] : [];
    foreach ($categories as $catKey => $cat) {
        $rows = ['' => $cat['name']];
        foreach (($cat['subcategories'] ?? []) as $subKey => $subName) $rows[$subKey] = $subName;
        foreach ($rows as $subKey => $label) {
            $in = $posted[$catKey][$subKey === '' ? '__cat' : $subKey] ?? null;
            if (!is_array($in)) continue;
            $allowed = $subKey === '' ? ['free', 'paid'] : ['inherit', 'free', 'paid'];
            $mode = in_array($in['mode'] ?? '', $allowed, true) ? $in['mode'] : ($subKey === '' ? 'free' : 'inherit');
            $raw = trim((string) ($in['price'] ?? ''));
            $price = ($raw !== '' && is_numeric($raw)) ? round((float) $raw, 2) : null;
            if ($mode === 'paid' && ($price === null || $price < 0.5)) {
                $problems[] = $subKey === '' ? $label : $cat['name'] . ' > ' . $label;     // keep the old rule for this row
                continue;
            }
            hl_lf_save_rule(hl_db(), $catKey, $subKey, $mode, $price);
        }
    }
    if ($problems) {
        $flash = 'Saved, except: ' . implode(', ', $problems) . ' (a paid category needs a price of at least $0.50).';
        $flashType = 'err';
    } else {
        $flash = 'Saved. The bot uses these fees for the next ad.';
    }
}

$enabled = hl_get_setting('lf_enabled', '0') === '1';
$rules = hl_lf_rules(hl_db());
$csrf = h(hl_csrf_token());
$fmt = function ($p) { return $p === null ? '' : rtrim(rtrim(number_format((float) $p, 2, '.', ''), '0'), '.'); };

hl_shell_head('Listing Fees', 'listingfees', hl_pending_count());
if ($flash) hl_flash($flash, $flashType);
?>
<style>
  .lf select{padding:8px 10px;border:1px solid var(--line);border-radius:9px;background:var(--input);color:var(--text);font-size:14px}
  .lf td{padding:8px}
  .lf .sub td:first-child{padding-left:28px;color:var(--muted)}
  .lf .cat td{background:var(--chip);font-weight:700}
  .lf .price{max-width:120px}
  .lf .switch{display:flex;gap:10px;align-items:center;font-size:14px;color:var(--text)}
  .lf .switch input{width:18px;height:18px}
</style>

<form method="post" class="lf">
  <input type="hidden" name="csrf" value="<?= $csrf ?>">
  <div class="card">
    <h2>Paid posting</h2>
    <p class="sub">When this is on, ads posted through the Telegram bot in a paid category are published only after the fee is paid by card. Ads posted directly on the website are not affected.</p>
    <label class="switch"><input type="checkbox" name="lf_enabled" value="1" <?= $enabled ? 'checked' : '' ?> data-lf-enabled>
      Charge a posting fee for paid categories</label>
    <p class="muted small" style="margin-top:8px">Currently: <b><?= $enabled ? 'ON - the fees below are charged' : 'OFF - all posting is free' ?></b></p>
  </div>

  <div class="card">
    <div class="hd"><h2>Fees by category</h2><button type="submit">Save fees</button></div>
    <p class="sub">A subcategory can use its category's fee, be free, or have its own fee. Minimum fee $0.50 (Stripe's minimum).</p>
    <div class="tblwrap"><table>
      <thead><tr><th>Category</th><th>Fee</th><th>Price (USD)</th><th>Charged now</th></tr></thead>
      <tbody>
      <?php foreach ($categories as $catKey => $cat):
          $cr = $rules[$catKey][''] ?? ['mode' => 'free', 'price' => null];
          $eff = hl_lf_fee_from_rules($rules, true, $catKey, ''); ?>
        <tr class="cat" data-lf-row="<?= h($catKey) ?>">
          <td><?= h($cat['name']) ?></td>
          <td><select name="rule[<?= h($catKey) ?>][__cat][mode]" aria-label="<?= h($cat['name']) ?> fee">
              <option value="free" <?= $cr['mode'] !== 'paid' ? 'selected' : '' ?>>Free</option>
              <option value="paid" <?= $cr['mode'] === 'paid' ? 'selected' : '' ?>>Paid</option></select></td>
          <td><div class="prefix price"><span class="sym">$</span><input type="number" min="0" step="0.01" name="rule[<?= h($catKey) ?>][__cat][price]" value="<?= h($fmt($cr['price'])) ?>" aria-label="<?= h($cat['name']) ?> price"></div></td>
          <td><?= $eff > 0 ? '<span class="pill pend">' . h(hl_lf_money($eff)) . '</span>' : '<span class="pill ok">Free</span>' ?></td>
        </tr>
        <?php foreach (($cat['subcategories'] ?? []) as $subKey => $subName):
            $sr = $rules[$catKey][$subKey] ?? ['mode' => 'inherit', 'price' => null];
            $eff = hl_lf_fee_from_rules($rules, true, $catKey, $subKey); ?>
          <tr class="sub" data-lf-row="<?= h($catKey . '/' . $subKey) ?>">
            <td><?= h($subName) ?></td>
            <td><select name="rule[<?= h($catKey) ?>][<?= h($subKey) ?>][mode]" aria-label="<?= h($subName) ?> fee">
                <option value="inherit" <?= $sr['mode'] === 'inherit' ? 'selected' : '' ?>>Same as category</option>
                <option value="free" <?= $sr['mode'] === 'free' ? 'selected' : '' ?>>Free</option>
                <option value="paid" <?= $sr['mode'] === 'paid' ? 'selected' : '' ?>>Own price</option></select></td>
            <td><div class="prefix price"><span class="sym">$</span><input type="number" min="0" step="0.01" name="rule[<?= h($catKey) ?>][<?= h($subKey) ?>][price]" value="<?= h($fmt($sr['price'])) ?>" aria-label="<?= h($subName) ?> price"></div></td>
            <td><?= $eff > 0 ? '<span class="pill pend">' . h(hl_lf_money($eff)) . '</span>' : '<span class="pill ok">Free</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="muted small" style="margin-top:10px">"Charged now" shows what a user pays when paid posting is ON. Listing payments appear under Payments.</p>
    <button type="submit">Save fees</button>
  </div>
</form>

<?php hl_shell_foot();
