<?php
/**
 * users.php - registered bot members (read-only).
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();

$rows = [];
$res = hl_db()->query("SELECT telegram_id, name, phone, email, registered_at
                       FROM users ORDER BY id DESC LIMIT 500");
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $rows[] = $r; }

hl_shell_head('Users', 'users', hl_pending_count());
?>

<div class="card">
  <div class="hd"><h2>Users<?= $rows ? ' (' . count($rows) . ')' : '' ?></h2></div>
  <p class="sub">People who have registered with the bot. Read-only for now.</p>
  <?php if (!$rows): ?>
    <div class="empty">No registered users yet.</div>
  <?php else: ?>
  <div class="tblwrap"><table>
    <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Telegram ID</th><th>Registered</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $u): ?>
      <tr>
        <td><b><?= h($u['name'] ?: '-') ?></b></td>
        <td class="muted"><?= h($u['phone'] ?: '-') ?></td>
        <td class="muted"><?= h($u['email'] ?: '-') ?></td>
        <td class="muted small mono"><?= h($u['telegram_id']) ?></td>
        <td class="muted small"><?= h($u['registered_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php hl_shell_foot();
