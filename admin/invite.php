<?php
/**
 * invite.php - admin control panel for the Invite & Earn referral system.
 *
 * Turn the feature on/off, configure the settle window, manage reward tiers,
 * approve/reject earned rewards, review (and moderate) referrals, grant rewards
 * manually, and read the audit log. All logic lives in HL_Referral so the bot
 * and this panel behave identically.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();
hl_session_start();

$root = hl_bot_root();
if ($root && is_file($root . '/includes/referral.php')) {
    require_once $root . '/includes/referral.php';
}
if (!class_exists('HL_Referral')) {
    hl_shell_head('Invite & Earn', 'invite', hl_pending_count());
    hl_flash('The Invite & Earn engine (includes/referral.php) is not uploaded to the bot folder yet. Upload it, then reload this page.', 'err');
    hl_shell_foot();
    exit;
}

$ref = new HL_Referral(BOT_DB_PATH);
$adminId = (int) ($_SESSION['hl_admin'] ?? 1);
$flash = null; $flashType = 'ok';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    hl_csrf_check();
    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'toggle_feature':
            $on = ($_POST['enabled'] ?? '0') === '1';
            $ref->setSetting('invite_earn_enabled', $on ? '1' : '0');
            $ref->audit('admin:' . $adminId, 'feature_toggled', null, null, $on ? 'ON' : 'OFF');
            $flash = 'Invite & Earn is now ' . ($on ? 'ON.' : 'OFF.');
            break;
        case 'save_settings':
            $days = max(0, (int) ($_POST['qualify_days'] ?? 7));
            $usa = ($_POST['usa_only'] ?? '0') === '1';
            $ref->setSetting('referral_qualify_days', (string) $days);
            $ref->setSetting('referral_usa_only', $usa ? '1' : '0');
            $ref->audit('admin:' . $adminId, 'config_changed', null, null, "qualify_days=$days usa_only=" . ($usa ? 1 : 0));
            $flash = 'Settings saved.';
            break;
        case 'tier_add':
            $inv = (int) ($_POST['invites'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $body = trim((string) ($_POST['body'] ?? ''));
            if ($inv > 0 && $title !== '') { $ref->addTier($inv, $title, $body); $flash = 'Reward tier added.'; }
            else { $flash = 'Please enter an invite count and a title.'; $flashType = 'err'; }
            break;
        case 'tier_save':
            $ref->updateTier((int) $_POST['tier_id'], (int) $_POST['invites'], trim((string) $_POST['title']),
                trim((string) ($_POST['body'] ?? '')), isset($_POST['active']));
            $flash = 'Reward tier updated.';
            break;
        case 'tier_delete':
            $ref->deleteTier((int) $_POST['tier_id']);
            $flash = 'Reward tier removed.';
            break;
        case 'reward_approve':
            $ref->approveReward((int) $_POST['reward_id'], $adminId,
                trim((string) ($_POST['start_date'] ?? '')) ?: null,
                trim((string) ($_POST['end_date'] ?? '')) ?: null,
                trim((string) ($_POST['notes'] ?? '')) ?: null);
            $flash = 'Reward approved.';
            break;
        case 'reward_reject':
            $ref->rejectReward((int) $_POST['reward_id'], $adminId, trim((string) ($_POST['reason'] ?? '')) ?: null);
            $flash = 'Reward rejected.';
            break;
        case 'referral_status':
            $st = $_POST['status'] ?? '';
            if (in_array($st, ['qualified', 'rejected'], true)) {
                $ref->setReferralStatus((int) $_POST['ref_id'], $st, $adminId);
                $flash = 'Referral marked ' . $st . '.';
            }
            break;
        case 'grant_reward':
            $tid = (int) ($_POST['telegram_id'] ?? 0);
            $tier = (int) ($_POST['tier_id'] ?? 0);
            if ($tid > 0 && $tier > 0) {
                $g = $ref->grantReward($tid, $tier, $adminId,
                    trim((string) ($_POST['start_date'] ?? '')) ?: null,
                    trim((string) ($_POST['end_date'] ?? '')) ?: null,
                    trim((string) ($_POST['notes'] ?? '')) ?: null);
                $flash = $g ? 'Reward granted to user ' . $tid . '.' : 'Could not grant (check the tier).';
                if (!$g) $flashType = 'err';
            } else { $flash = 'Enter a Telegram user ID and pick a tier.'; $flashType = 'err'; }
            break;
    }
}
$csrf = h(hl_csrf_token());

$enabled   = $ref->isEnabled();
$stats     = $ref->stats();
$tiers     = $ref->tiers(false);
$pending   = $ref->listRewards('earned');
$allReward = $ref->listRewards(null, 60);
$referrals = $ref->listReferrals(120);
$audit     = $ref->listAudit(40);

function inv_status_pill($status) {
    switch ($status) {
        case 'approved': case 'qualified': return ['ok', ucfirst($status)];
        case 'earned':   return ['pend', 'Pending'];
        case 'registered': return ['pend', 'Settling'];
        case 'rejected': return ['rej', 'Rejected'];
        default: return ['mut', ucfirst(str_replace('_', ' ', (string) $status))];
    }
}

hl_shell_head('Invite & Earn', 'invite', hl_pending_count());
if ($flash) hl_flash($flash, $flashType);
?>
<style>
  .grid4{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:6px}
  .kpi{background:var(--chip);border:1px solid var(--line);border-radius:10px;padding:12px 14px}
  .kpi .n{font-size:22px;font-weight:800}
  .kpi .l{font-size:12px;color:var(--muted);margin-top:2px}
  .switch{display:inline-flex;align-items:center;gap:10px}
  .pill.on{background:rgba(46,160,67,.16);color:#3fb950}
  .pill.off{background:var(--chip);color:var(--muted)}
  .tier{border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:10px;background:var(--chip)}
  .tier textarea{min-height:56px;resize:vertical}
  .row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
  .row .f{flex:1;min-width:120px}
  .mini{font-size:12px;color:var(--muted)}
  .inl{display:inline}
  table td .mini{display:block}
  .approveform{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
  .approveform input[type=date]{width:auto}
</style>

<div class="card">
  <div class="hd"><h2>Invite &amp; Earn</h2>
    <form method="post" class="switch">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="toggle_feature">
      <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
      <span class="pill <?= $enabled ? 'on' : 'off' ?>"><?= $enabled ? 'ON' : 'OFF' ?></span>
      <button class="btn sm <?= $enabled ? 'red' : '' ?>" type="submit"><?= $enabled ? 'Turn OFF' : 'Turn ON' ?></button>
    </form>
  </div>
  <p class="sub">The referral program customers use to invite friends and earn rewards. Turn it on/off, set the rules, and approve rewards below.</p>
  <div class="grid4">
    <div class="kpi"><div class="n"><?= (int) $stats['referrals'] ?></div><div class="l">Total referrals</div></div>
    <div class="kpi"><div class="n"><?= (int) $stats['qualified'] ?></div><div class="l">Qualified</div></div>
    <div class="kpi"><div class="n"><?= (int) $stats['flagged'] ?></div><div class="l">Flagged for review</div></div>
    <div class="kpi"><div class="n"><?= (int) $stats['rewards_pending'] ?></div><div class="l">Rewards pending</div></div>
    <div class="kpi"><div class="n"><?= (int) $stats['rewards_approved'] ?></div><div class="l">Rewards approved</div></div>
  </div>
</div>

<div class="card">
  <div class="hd"><h2>Rules &amp; Eligibility</h2></div>
  <form method="post" class="row">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="save_settings">
    <div class="f">
      <label>Settle window (days before a referral counts)</label>
      <input type="number" name="qualify_days" min="0" max="90" value="<?= (int) $ref->qualifyDays() ?>">
      <div class="mini">A referral counts toward rewards only after the invited friend has been registered this many days. Set 0 to count immediately (handy for testing).</div>
    </div>
    <div class="f">
      <label>USA-only notice</label>
      <select name="usa_only">
        <option value="0" <?= (string) $ref->setting('referral_usa_only','0')==='0'?'selected':'' ?>>Off</option>
        <option value="1" <?= (string) $ref->setting('referral_usa_only','0')==='1'?'selected':'' ?>>Show "USA only" note in the bot</option>
      </select>
      <div class="mini">Shows an informational line in the bot. Location isn't verified automatically.</div>
    </div>
    <div><button class="btn" type="submit">Save rules</button></div>
  </form>
</div>

<div class="card">
  <div class="hd"><h2>Reward Tiers</h2></div>
  <p class="sub">How many successful invites unlock each reward, and the reward content shown to users. Put each benefit on its own line.</p>
  <?php foreach ($tiers as $t): ?>
    <form method="post" class="tier">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="tier_id" value="<?= (int) $t['id'] ?>">
      <div class="row">
        <div style="width:120px"><label>Invites</label>
          <input type="number" name="invites" min="1" value="<?= (int) $t['invites_required'] ?>"></div>
        <div class="f"><label>Title</label>
          <input type="text" name="title" value="<?= h($t['title']) ?>"></div>
        <div style="width:auto"><label>Active</label>
          <input type="checkbox" name="active" <?= (int) $t['active'] === 1 ? 'checked' : '' ?> style="width:auto;height:20px"></div>
      </div>
      <div class="f" style="margin-top:8px"><label>Reward content (one benefit per line)</label>
        <textarea name="body"><?= h($t['body']) ?></textarea></div>
      <div class="row" style="margin-top:8px">
        <button class="btn sm" type="submit" name="action" value="tier_save">Save</button>
        <button class="btn sm red" type="submit" name="action" value="tier_delete"
          onclick="return confirm('Remove this reward tier?')">Delete</button>
      </div>
    </form>
  <?php endforeach; ?>

  <form method="post" class="tier" style="border-style:dashed">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="tier_add">
    <b class="mini">Add a new reward tier</b>
    <div class="row" style="margin-top:8px">
      <div style="width:120px"><label>Invites</label><input type="number" name="invites" min="1" placeholder="e.g. 150"></div>
      <div class="f"><label>Title</label><input type="text" name="title" placeholder="Reward name"></div>
    </div>
    <div class="f" style="margin-top:8px"><label>Reward content</label><textarea name="body" placeholder="One benefit per line"></textarea></div>
    <div style="margin-top:8px"><button class="btn sm" type="submit">Add tier</button></div>
  </form>
</div>

<div class="card">
  <div class="hd"><h2>Rewards Pending Approval<?= $pending ? ' (' . count($pending) . ')' : '' ?></h2></div>
  <?php if (!$pending): ?>
    <div class="empty">No rewards waiting. Approvals appear here when a user reaches a milestone.</div>
  <?php else: ?>
  <div class="tblwrap"><table>
    <thead><tr><th>User</th><th>Reward</th><th>Requested start</th><th>Approve with dates</th></tr></thead>
    <tbody>
    <?php foreach ($pending as $rw): ?>
      <tr>
        <td><b><?= h($rw['user_name'] ?: ('#' . $rw['telegram_id'])) ?></b><span class="mini">ID <?= (int) $rw['telegram_id'] ?></span></td>
        <td><?= h($rw['title']) ?><span class="mini"><?= (int) $rw['tier_invites'] ?> invites</span></td>
        <td class="mini"><?= h($rw['start_date'] ?: '-') ?><?= $rw['notes'] ? '<br>' . h($rw['notes']) : '' ?></td>
        <td>
          <form method="post" class="approveform">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="reward_id" value="<?= (int) $rw['id'] ?>">
            <input type="date" name="start_date" value="<?= h(substr((string) $rw['start_date'], 0, 10)) ?>">
            <input type="date" name="end_date">
            <button class="btn sm" type="submit" name="action" value="reward_approve">Approve</button>
            <button class="btn sm red" type="submit" name="action" value="reward_reject"
              onclick="return confirm('Reject this reward?')">Reject</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="hd"><h2>Grant a Reward Manually</h2></div>
  <p class="sub">Give a user a reward without them needing to invite anyone. It's recorded as approved right away.</p>
  <form method="post" class="row">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="grant_reward">
    <div style="width:160px"><label>Telegram user ID</label><input type="number" name="telegram_id" placeholder="e.g. 123456789"></div>
    <div class="f"><label>Reward tier</label>
      <select name="tier_id">
        <?php foreach ($tiers as $t): ?>
          <option value="<?= (int) $t['id'] ?>"><?= h($t['title']) ?> (<?= (int) $t['invites_required'] ?> invites)</option>
        <?php endforeach; ?>
      </select></div>
    <div style="width:150px"><label>Start</label><input type="date" name="start_date"></div>
    <div style="width:150px"><label>End</label><input type="date" name="end_date"></div>
    <div><button class="btn" type="submit">Grant</button></div>
  </form>
</div>

<div class="card">
  <div class="hd"><h2>Referrals<?= $referrals ? ' (' . count($referrals) . ')' : '' ?></h2></div>
  <?php if (!$referrals): ?>
    <div class="empty">No referrals yet.</div>
  <?php else: ?>
  <div class="tblwrap"><table>
    <thead><tr><th>Invited friend</th><th>Invited by</th><th>Status</th><th>When</th><th>Action</th></tr></thead>
    <tbody>
    <?php foreach ($referrals as $r): list($pc, $pl) = inv_status_pill($r['status']); ?>
      <tr>
        <td><b><?= h($r['referred_name'] ?: ('#' . $r['referred_id'])) ?></b><span class="mini">ID <?= (int) $r['referred_id'] ?></span></td>
        <td><?= h($r['referrer_name'] ?: ('#' . $r['referrer_id'])) ?><span class="mini">ID <?= (int) $r['referrer_id'] ?></span></td>
        <td><span class="pill <?= $pc ?>"><?= h($pl) ?></span>
            <?= $r['flagged'] ? '<span class="pill rej" title="' . h((string) $r['flag_reason']) . '">Flagged</span>' : '' ?></td>
        <td class="mini"><?= h($r['created_at']) ?></td>
        <td>
          <?php if ($r['status'] !== 'qualified'): ?>
          <form method="post" class="inl"><input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="referral_status"><input type="hidden" name="ref_id" value="<?= (int) $r['id'] ?>">
            <input type="hidden" name="status" value="qualified">
            <button class="btn sm" type="submit" title="Count this referral now">Approve</button></form>
          <?php endif; ?>
          <?php if ($r['status'] !== 'rejected'): ?>
          <form method="post" class="inl" onsubmit="return confirm('Reject this referral so it never counts?')">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="referral_status"><input type="hidden" name="ref_id" value="<?= (int) $r['id'] ?>">
            <input type="hidden" name="status" value="rejected">
            <button class="btn sm red" type="submit">Reject</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="hd"><h2>All Rewards</h2></div>
  <?php if (!$allReward): ?>
    <div class="empty">No rewards yet.</div>
  <?php else: ?>
  <div class="tblwrap"><table>
    <thead><tr><th>User</th><th>Reward</th><th>Source</th><th>Status</th><th>Dates</th></tr></thead>
    <tbody>
    <?php foreach ($allReward as $rw): list($pc, $pl) = inv_status_pill($rw['status']); ?>
      <tr>
        <td><b><?= h($rw['user_name'] ?: ('#' . $rw['telegram_id'])) ?></b><span class="mini">ID <?= (int) $rw['telegram_id'] ?></span></td>
        <td><?= h($rw['title']) ?></td>
        <td class="mini"><?= h($rw['source']) ?></td>
        <td><span class="pill <?= $pc ?>"><?= h($pl) ?></span></td>
        <td class="mini"><?= h($rw['start_date'] ?: '-') ?><?= $rw['end_date'] ? ' &rarr; ' . h($rw['end_date']) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="hd"><h2>Audit Log</h2></div>
  <p class="sub">Every referral and reward change, most recent first.</p>
  <div class="tblwrap"><table>
    <thead><tr><th>When</th><th>Who</th><th>Event</th><th>Detail</th></tr></thead>
    <tbody>
    <?php foreach ($audit as $a): ?>
      <tr>
        <td class="mini"><?= h($a['ts']) ?></td>
        <td class="mini"><?= h($a['actor']) ?></td>
        <td><span class="pill mut"><?= h(str_replace('_', ' ', (string) $a['event'])) ?></span></td>
        <td class="mini"><?= h((string) $a['detail']) ?><?= $a['telegram_id'] ? ' (user ' . (int) $a['telegram_id'] . ')' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php hl_shell_foot();
