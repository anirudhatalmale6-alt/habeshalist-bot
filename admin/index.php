<?php
/**
 * index.php - the admin dashboard.
 *  - Edit package prices (writes settings key price_<pkg>) - live for the bot.
 *  - Edit payment handles (pay_zelle / pay_cashapp / pay_support) - live.
 *  - At-a-glance stats + recent promotions (read-only in this milestone).
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();

$flash = null; $flashType = 'ok';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hl_csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'prices') {
        $saved = 0;
        foreach (HL_PACKAGES as $key => $meta) {
            if (!isset($_POST['price_' . $key])) continue;
            $raw = trim($_POST['price_' . $key]);
            if ($raw === '' || !is_numeric($raw) || $raw < 0) continue;
            // Store as an integer if whole, else keep decimals - the bot casts as needed.
            $val = ($raw == (int)$raw) ? (string)(int)$raw : (string)(0 + $raw);
            hl_set_setting('price_' . $key, $val);
            $saved++;
        }
        $flash = "Saved $saved package price(s). The bot will use the new prices on the next message.";
    } elseif ($action === 'handles') {
        foreach (HL_PAY_HANDLES as $key => $meta) {
            if (!isset($_POST[$key])) continue;
            hl_set_setting($key, trim($_POST[$key]));
        }
        $flash = 'Payment handles saved. These now show in the bot payment screen instantly.';
    }
}

// ---- gather current values + stats ----
$prices = [];
foreach (HL_PACKAGES as $key => $meta) {
    $prices[$key] = hl_get_setting('price_' . $key, (string)$meta['default']);
}
$handles = [];
foreach (HL_PAY_HANDLES as $key => $meta) {
    $handles[$key] = hl_get_setting($key, $key === 'pay_support' ? '@Habesha_list' : '');
}

$stat_users = hl_count('SELECT COUNT(*) FROM users');
$stat_ads   = hl_count('SELECT COUNT(*) FROM ads');
$stat_promos = hl_count('SELECT COUNT(*) FROM promotions');
$stat_pending = hl_count("SELECT COUNT(*) FROM promotions WHERE status='pending'");
$stat_approved = hl_count("SELECT COUNT(*) FROM promotions WHERE status='approved'");
$rev = hl_db()->query("SELECT COALESCE(SUM(price),0) FROM promotions WHERE status='approved'");
$revenue = $rev ? (float) ($rev->fetchArray(SQLITE3_NUM)[0] ?? 0) : 0;

// recent promotions
$recent = [];
$res = hl_db()->query("SELECT business_name, package_key, price, payment_method, payment_status, status, created_at
                       FROM promotions ORDER BY id DESC LIMIT 15");
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $recent[] = $r; }

function pillClass($status) {
    switch ($status) {
        case 'approved': case 'verified': return 'ok';
        case 'pending': case 'awaiting_verification': return 'pend';
        case 'rejected': return 'rej';
        default: return 'mut';
    }
}

hl_head('Dashboard', true);
if ($flash) hl_flash($flash, $flashType);
$csrf = h(hl_csrf_token());
?>

<div class="stats">
  <div class="stat"><div class="n"><?= $stat_users ?></div><div class="l">Registered users</div></div>
  <div class="stat"><div class="n"><?= $stat_ads ?></div><div class="l">Classified ads</div></div>
  <div class="stat"><div class="n"><?= $stat_promos ?></div><div class="l">Promotions</div></div>
  <div class="stat"><div class="n"><?= $stat_pending ?></div><div class="l">Pending review</div></div>
  <div class="stat"><div class="n">$<?= number_format($revenue, 0) ?></div><div class="l">Approved revenue</div></div>
</div>

<div class="card">
  <h2>Package prices</h2>
  <p class="sub">Change a price here and the bot uses it immediately - no restart. These map to the same settings the in-bot /promoadmin command edits.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="prices">
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

<div class="card">
  <h2>Payment handles</h2>
  <p class="sub">Shown to users on the manual payment screen ("Please send payment to: ..."). Leave a handle blank to hide that method's details and fall back to "contact support".</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="handles">
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

<div class="card">
  <h2>Recent promotions</h2>
  <p class="sub">The latest business promotions submitted through the bot. Approving/rejecting from the web is the next step - for now these are managed from Telegram.</p>
  <?php if (!$recent): ?>
    <p class="muted">No promotions yet.</p>
  <?php else: ?>
  <div class="tblwrap">
  <table>
    <thead><tr><th>Business</th><th>Package</th><th>Price</th><th>Method</th><th>Payment</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r):
        $pkg = HL_PACKAGES[$r['package_key']]['name'] ?? ($r['package_key'] ?: '-'); ?>
      <tr>
        <td><?= h($r['business_name'] ?: '(draft)') ?></td>
        <td><?= h($pkg) ?></td>
        <td>$<?= h(rtrim(rtrim(number_format((float)$r['price'], 2), '0'), '.')) ?></td>
        <td><?= h($r['payment_method'] ?: '-') ?></td>
        <td><span class="pill <?= pillClass($r['payment_status']) ?>"><?= h($r['payment_status'] ?: '-') ?></span></td>
        <td><span class="pill <?= pillClass($r['status']) ?>"><?= h($r['status'] ?: '-') ?></span></td>
        <td class="muted small"><?= h($r['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<p class="muted small">Signed in as <?= h($_SESSION['hl_admin']) ?>. Changes here write to the same database the Telegram bot reads, so they take effect immediately.</p>

<?php hl_foot();
