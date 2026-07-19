<?php
/**
 * users.php - registered bot members, with a per-user Delete for testing.
 *
 * Deleting a user wipes every trace of that Telegram account so it behaves like
 * a brand-new person the next time they message the bot: the user record, their
 * conversation state, and ALL referral data (any referral where they were the
 * inviter or the invited friend, plus their earned rewards). This lets the
 * referral flow be re-tested from scratch with the same Telegram accounts.
 *
 * Business records (ads / paid promotions / payment history) are intentionally
 * left untouched so the Businesses, Payments and Scheduled views stay intact.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();

// Does a table exist? Referral tables only exist once the bot's referral engine
// has run at least once, so guard before touching them.
function hl_table_exists($name) {
    $st = hl_db()->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=:n");
    $st->bindValue(':n', $name, SQLITE3_TEXT);
    return (bool) ($st->execute()->fetchArray(SQLITE3_ASSOC));
}

$flash = null; $flashType = 'ok';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    hl_csrf_check();
    $act = $_POST['action'] ?? '';
    $tid = (int) ($_POST['telegram_id'] ?? 0);

    if ($act === 'delete' && $tid !== 0) {
        $db = hl_db();
        // Grab the name first for a friendly confirmation message.
        $ns = $db->prepare("SELECT name FROM users WHERE telegram_id = :t");
        $ns->bindValue(':t', $tid, SQLITE3_INTEGER);
        $urow = $ns->execute()->fetchArray(SQLITE3_ASSOC);
        $who = ($urow && $urow['name'] !== '') ? $urow['name'] : ('ID ' . $tid);

        $del = function ($sql) use ($db, $tid) {
            $s = $db->prepare($sql);
            $s->bindValue(':t', $tid, SQLITE3_INTEGER);
            $s->execute();
            return $db->changes();
        };

        $del("DELETE FROM users WHERE telegram_id = :t");
        $del("DELETE FROM user_states WHERE telegram_id = :t");

        $refCleared = 0;
        if (hl_table_exists('referrals')) {
            $refCleared += $del("DELETE FROM referrals WHERE referred_id = :t");
            $refCleared += $del("DELETE FROM referrals WHERE referrer_id = :t");
        }
        if (hl_table_exists('referral_rewards')) {
            $del("DELETE FROM referral_rewards WHERE telegram_id = :t");
        }

        $flash = "Deleted " . $who . " and reset their referral data" .
                 ($refCleared ? " ({$refCleared} referral record" . ($refCleared === 1 ? '' : 's') . " cleared)" : '') .
                 ". They'll be treated as a brand-new user next time.";
    }
}

// Load the user list (after any delete above).
$rows = [];
$res = hl_db()->query("SELECT telegram_id, name, phone, email, registered_at
                       FROM users ORDER BY id DESC LIMIT 500");
while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $rows[] = $r; }

// Map telegram_id -> how many people they've invited (qualified or not), so the
// admin can see who is worth keeping vs a throwaway test account.
$invited = [];
if (hl_table_exists('referrals')) {
    $ir = hl_db()->query("SELECT referrer_id, COUNT(*) c FROM referrals GROUP BY referrer_id");
    while ($ir && ($x = $ir->fetchArray(SQLITE3_ASSOC))) { $invited[(int) $x['referrer_id']] = (int) $x['c']; }
}

$csrf = h(hl_csrf_token());
hl_shell_head('Users', 'users', hl_pending_count());
if ($flash) hl_flash($flash, $flashType);
?>

<div class="card">
  <div class="hd"><h2>Users<?= $rows ? ' (' . count($rows) . ')' : '' ?></h2></div>
  <p class="sub">People who have registered with the bot. Use Delete to fully remove a test account - it clears their registration, conversation state and all referral data so the invite flow can be re-tested from scratch. Business records (ads, promotions, payments) are left untouched.</p>
  <?php if (!$rows): ?>
    <div class="empty">No registered users yet.</div>
  <?php else: ?>
  <div class="tblwrap"><table>
    <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Telegram ID</th><th>Invited</th><th>Registered</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $u): $tid = (int) $u['telegram_id']; ?>
      <tr>
        <td><b><?= h($u['name'] ?: '-') ?></b></td>
        <td class="muted"><?= h($u['phone'] ?: '-') ?></td>
        <td class="muted"><?= h($u['email'] ?: '-') ?></td>
        <td class="muted small mono"><?= h($tid) ?></td>
        <td class="muted small"><?= isset($invited[$tid]) ? (int) $invited[$tid] : 0 ?></td>
        <td class="muted small"><?= h($u['registered_at']) ?></td>
        <td>
          <form method="post" style="margin:0" onsubmit="return confirm('Delete <?= h(addslashes($u['name'] ?: ('ID ' . $tid))) ?>? This removes their account and all referral data. Business records are kept. This cannot be undone.');">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="telegram_id" value="<?= $tid ?>">
            <button type="submit" class="btn red sm">Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php hl_shell_foot();
