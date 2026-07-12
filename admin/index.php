<?php
/**
 * index.php - dashboard. Live stat cards, a Pending Ads queue with
 * approve/reject (customer notified in Telegram), and recent payments.
 * Prices / payment handles / keys now live on their own pages (see sidebar).
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();

$mod = hl_process_moderation();       // approve/reject from the dashboard
$flash = $mod[0] ?? null; $flashType = $mod[1] ?? 'ok';

// ---- stats ----
$stat_ads      = hl_count('SELECT COUNT(*) FROM ads');
$stat_posted   = hl_count('SELECT COALESCE(SUM(posts_used),0) FROM promotions');
$stat_sched    = hl_count("SELECT COUNT(*) FROM promotions WHERE status='approved'");
$rev = hl_db()->query("SELECT COALESCE(SUM(price),0) FROM promotions WHERE status='approved'");
$revenue = $rev ? (float) ($rev->fetchArray(SQLITE3_NUM)[0] ?? 0) : 0;
$stat_biz      = hl_count("SELECT COUNT(DISTINCT business_name) FROM promotions WHERE status='approved' AND business_name IS NOT NULL AND business_name<>''");
$stat_members  = hl_count('SELECT COUNT(*) FROM users');
$pending_count = hl_count("SELECT COUNT(*) FROM promotions WHERE status='pending_review'");

$cards = [
    ['n' => number_format($stat_ads),      'l' => 'Total Ads',         'ico' => "\xF0\x9F\x93\xA2", 'c' => '46,120,229'],
    ['n' => number_format($stat_posted),   'l' => 'Posted in Group',   'ico' => "\xE2\x9C\x85",     'c' => '46,160,67'],
    ['n' => number_format($stat_sched),    'l' => 'Scheduled',         'ico' => "\xE2\x8F\xB0",     'c' => '210,153,34'],
    ['n' => hl_money($revenue),            'l' => 'Revenue',           'ico' => "\xF0\x9F\x92\xB0", 'c' => '139,92,246'],
    ['n' => number_format($stat_biz),      'l' => 'Active Businesses', 'ico' => "\xF0\x9F\x8F\xA2", 'c' => '20,158,158'],
    ['n' => number_format($stat_members),  'l' => 'Total Members',     'ico' => "\xF0\x9F\x91\xA5", 'c' => '99,102,241'],
];

// ---- pending queue (recent 6) ----
$pending = [];
$res = hl_db()->query("SELECT id, business_name, package_key, price, payment_method, created_at
                       FROM promotions WHERE status='pending_review' ORDER BY id DESC LIMIT 6");
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $pending[] = $r; }

// ---- recent payments (recent 6 priced promotions) ----
$pays = [];
$res = hl_db()->query("SELECT business_name, package_key, price, payment_method, payment_status, created_at
                       FROM promotions WHERE price IS NOT NULL AND price>0 ORDER BY id DESC LIMIT 6");
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $pays[] = $r; }

hl_session_start();
$csrf = h(hl_csrf_token());
hl_shell_head('Dashboard', 'dashboard', $pending_count);
if ($flash) hl_flash($flash, $flashType);
?>

<div class="stats">
  <?php foreach ($cards as $c): ?>
  <div class="stat">
    <div class="ico" style="background:rgba(<?= $c['c'] ?>,.14);color:rgb(<?= $c['c'] ?>)"><?= $c['ico'] ?></div>
    <div><div class="n"><?= h($c['n']) ?></div><div class="l"><?= h($c['l']) ?></div></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="grid2">
  <div class="card">
    <div class="hd">
      <h2>Pending Ads<?= $pending_count > 0 ? ' (' . $pending_count . ')' : '' ?></h2>
      <a class="btn ghost sm" href="pending.php">View all</a>
    </div>
    <?php if (!$pending): ?>
      <div class="empty">Nothing waiting for review right now.</div>
    <?php else: ?>
    <div class="tblwrap"><table>
      <thead><tr><th>Business</th><th>Plan</th><th>Submitted</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($pending as $p): ?>
        <tr>
          <td><b><?= h($p['business_name'] ?: '(no name)') ?></b></td>
          <td><span class="plan"><?= h(hl_plan_name($p['package_key'])) ?> &middot; <?= h(hl_money($p['price'])) ?></span></td>
          <td class="muted small"><?= h($p['created_at']) ?></td>
          <td>
            <div class="actions">
              <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="approve"><input type="hidden" name="promo_id" value="<?= (int) $p['id'] ?>">
                <button class="btn sm" type="submit">Approve</button></form>
              <form method="post" onsubmit="return confirm('Reject this promotion? The customer will be told in Telegram.')">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="reject"><input type="hidden" name="promo_id" value="<?= (int) $p['id'] ?>">
                <button class="btn sm red" type="submit">Reject</button></form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="hd">
      <h2>Recent Payments</h2>
      <a class="btn ghost sm" href="payments.php">View all</a>
    </div>
    <?php if (!$pays): ?>
      <div class="empty">No payments yet.</div>
    <?php else: ?>
    <div class="tblwrap"><table>
      <thead><tr><th>Business</th><th>Plan</th><th>Amount</th><th>Method</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($pays as $p): list($pc, $pl) = hl_status_meta($p['payment_status']); ?>
        <tr>
          <td><?= h($p['business_name'] ?: '-') ?></td>
          <td><span class="plan"><?= h(hl_plan_name($p['package_key'])) ?></span></td>
          <td><b><?= h(hl_money($p['price'])) ?></b></td>
          <td class="muted"><?= h($p['payment_method'] ? ucfirst($p['payment_method']) : '-') ?></td>
          <td><span class="pill <?= $pc ?>"><?= h($pl) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<p class="muted small">Approvals here update the same database the bot reads and message the customer instantly in Telegram - the same as the in-bot approval. Scheduling, calendar slots and auto-posting to the group are the next milestone.</p>

<?php hl_shell_foot();
