<?php
/**
 * payments.php - a read-only ledger of promotion payments.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();

$rows = [];
$res = hl_db()->query("SELECT business_name, package_key, price, payment_method, payment_status, status, created_at
                       FROM promotions WHERE price IS NOT NULL AND price>0 ORDER BY id DESC LIMIT 300");
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $rows[] = $r; }

$total = 0.0;
foreach ($rows as $r) { if (in_array($r['status'], ['approved'], true)) $total += (float) $r['price']; }

hl_shell_head('Payments', 'payments', hl_pending_count());
?>

<div class="stats">
  <div class="stat"><div class="ico" style="background:rgba(139,92,246,.14);color:rgb(139,92,246)">&#128176;</div>
    <div><div class="n"><?= h(hl_money($total)) ?></div><div class="l">Verified revenue</div></div></div>
  <div class="stat"><div class="ico" style="background:rgba(46,120,229,.14);color:rgb(46,120,229)">&#129534;</div>
    <div><div class="n"><?= count($rows) ?></div><div class="l">Payment records</div></div></div>
</div>

<div class="card">
  <div class="hd"><h2>Payments</h2></div>
  <p class="sub">Every priced promotion and where its payment stands. Verified means you approved it.</p>
  <?php if (!$rows): ?>
    <div class="empty">No payments yet.</div>
  <?php else: ?>
  <div class="tblwrap"><table>
    <thead><tr><th>Business</th><th>Plan</th><th>Amount</th><th>Method</th><th>Date</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): list($pc, $pl) = hl_status_meta($p['payment_status']); ?>
      <tr>
        <td><?= h($p['business_name'] ?: '-') ?></td>
        <td><span class="plan"><?= h(hl_plan_name($p['package_key'])) ?></span></td>
        <td><b><?= h(hl_money($p['price'])) ?></b></td>
        <td class="muted"><?= h($p['payment_method'] ? ucfirst($p['payment_method']) : '-') ?></td>
        <td class="muted small"><?= h($p['created_at']) ?></td>
        <td><span class="pill <?= $pc ?>"><?= h($pl) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php hl_shell_foot();
