<?php
/**
 * pending.php - the full Pending Ads queue. Approve/reject each promotion;
 * the customer is notified in Telegram, exactly like the in-bot approval.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();

$mod = hl_process_moderation();
$flash = $mod[0] ?? null; $flashType = $mod[1] ?? 'ok';

$rows = [];
$res = hl_db()->query("SELECT id, business_name, business_category, package_key, price, payment_method, receipt, created_at
                       FROM promotions WHERE status='pending_review' ORDER BY id DESC LIMIT 200");
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $rows[] = $r; }

hl_session_start();
$csrf = h(hl_csrf_token());
hl_shell_head('Pending Ads', 'pending', count($rows));
if ($flash) hl_flash($flash, $flashType);
?>

<div class="card">
  <div class="hd">
    <h2>Pending Ads<?= $rows ? ' (' . count($rows) . ')' : '' ?></h2>
  </div>
  <p class="sub">Business promotions waiting for review. Approve to mark it paid/verified and schedule it, or Reject. Either way the customer gets an instant Telegram message.</p>
  <?php if (!$rows): ?>
    <div class="empty">All caught up - nothing waiting for review.</div>
  <?php else: ?>
  <div class="tblwrap"><table>
    <thead><tr><th>Business</th><th>Category</th><th>Plan</th><th>Receipt</th><th>Submitted</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): ?>
      <tr>
        <td><b><?= h($p['business_name'] ?: '(no name)') ?></b></td>
        <td class="muted"><?= h($p['business_category'] ?: '-') ?></td>
        <td><span class="plan"><?= h(hl_plan_name($p['package_key'])) ?> &middot; <?= h(hl_money($p['price'])) ?></span></td>
        <td class="muted small mono"><?= h($p['receipt'] ?: '-') ?></td>
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

<p class="muted small">Note: the ad photos and payment proof still arrive in your Telegram admin chat when a promotion is submitted. Viewing those inside this panel is part of a later milestone.</p>

<?php hl_shell_foot();
