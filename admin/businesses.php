<?php
/**
 * businesses.php - businesses that have run (or tried to run) a promotion,
 * grouped, with a quick spend + activity summary.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();

$rows = [];
$res = hl_db()->query("
    SELECT business_name,
           COUNT(*) AS promos,
           SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) AS approved,
           SUM(CASE WHEN status='approved' THEN price ELSE 0 END) AS spent,
           MAX(created_at) AS last_at
    FROM promotions
    WHERE business_name IS NOT NULL AND business_name<>''
    GROUP BY business_name
    ORDER BY last_at DESC LIMIT 300");
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $rows[] = $r; }

hl_shell_head('Businesses', 'businesses', hl_pending_count());
?>

<div class="card">
  <div class="hd"><h2>Businesses<?= $rows ? ' (' . count($rows) . ')' : '' ?></h2></div>
  <p class="sub">Every business that has submitted a promotion, with how many they have run and what they have spent.</p>
  <?php if (!$rows): ?>
    <div class="empty">No businesses yet.</div>
  <?php else: ?>
  <div class="tblwrap"><table>
    <thead><tr><th>Business</th><th>Promotions</th><th>Approved</th><th>Total spent</th><th>Last activity</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $b): ?>
      <tr>
        <td><b><?= h($b['business_name']) ?></b></td>
        <td><?= (int) $b['promos'] ?></td>
        <td><?= (int) $b['approved'] ?></td>
        <td><b><?= h(hl_money($b['spent'])) ?></b></td>
        <td class="muted small"><?= h($b['last_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php hl_shell_foot();
