<?php
/**
 * invite.php - admin control panel for the Invite & Earn referral system.
 *
 * Turn the feature on/off, configure the rules, manage reward tiers (including
 * which promotion each reward grants when redeemed), monitor referrals and
 * rewards, optionally gift a reward, and read the audit log.
 *
 * Referrals are verified AUTOMATICALLY (join the group + settle window) and
 * rewards are earned automatically - there is no manual approve/reject step.
 * All logic lives in HL_Referral so the bot and this panel behave identically.
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

// Promotion packages a reward can grant when redeemed. '' = the team sets it up
// manually (e.g. a multi-part bundle that isn't a single package).
$HL_FULFILL_PACKAGES = [
    ''         => 'Manual - our team sets it up',
    'one_time' => 'One-Time Post',
    'monthly'  => 'Monthly',
    'yearly'   => 'Yearly',
    'botw'     => 'Business of the Week',
];

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
            $reqJoin = ($_POST['require_group_join'] ?? '0') === '1';
            $ref->setSetting('referral_qualify_days', (string) $days);
            $ref->setSetting('referral_usa_only', $usa ? '1' : '0');
            $ref->setSetting('referral_require_group_join', $reqJoin ? '1' : '0');
            $ref->audit('admin:' . $adminId, 'config_changed', null, null, "qualify_days=$days usa_only=" . ($usa ? 1 : 0) . " require_group_join=" . ($reqJoin ? 1 : 0));
            $flash = 'Settings saved.';
            break;
        case 'tier_add':
            $inv = (int) ($_POST['invites'] ?? 0);
            $title = trim((string) ($_POST['title'] ?? ''));
            $body = trim((string) ($_POST['body'] ?? ''));
            $pk = (string) ($_POST['fulfill_package'] ?? '');
            if (!isset($HL_FULFILL_PACKAGES[$pk])) $pk = '';
            if ($inv > 0 && $title !== '') { $ref->addTier($inv, $title, $body, $pk); $flash = 'Reward tier added.'; }
            else { $flash = 'Please enter an invite count and a title.'; $flashType = 'err'; }
            break;
        case 'tier_save':
            $pk = (string) ($_POST['fulfill_package'] ?? '');
            if (!isset($HL_FULFILL_PACKAGES[$pk])) $pk = '';
            $ref->updateTier((int) $_POST['tier_id'], (int) $_POST['invites'], trim((string) $_POST['title']),
                trim((string) ($_POST['body'] ?? '')), isset($_POST['active']), $pk);
            $flash = 'Reward tier updated.';
            break;
        case 'tier_delete':
            $ref->deleteTier((int) $_POST['tier_id']);
            $flash = 'Reward tier removed.';
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
$allReward = $ref->listRewards(null, 60);
$referrals = $ref->listReferrals(120);
$audit     = $ref->listAudit(40);
$needJoin  = $ref->requiresGroupJoin();

function inv_status_pill($status) {
    switch ($status) {
        case 'approved': case 'qualified': case 'redeemed': return ['ok', ucfirst($status)];
        case 'earned':   return ['pend', 'Ready to redeem'];
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
  <p class="sub">The referral program customers use to invite friends and earn rewards. Invites are verified automatically (group join + settle window) and rewards unlock automatically - no manual approval needed.</p>
  <div class="grid4">
    <div class="kpi"><div class="n"><?= (int) $stats['referrals'] ?></div><div class="l">Total referrals</div></div>
    <div class="kpi"><div class="n"><?= (int) $stats['qualified'] ?></div><div class="l">Qualified</div></div>
    <div class="kpi"><div class="n"><?= (int) $stats['flagged'] ?></div><div class="l">Flagged for review</div></div>
    <div class="kpi"><div class="n"><?= (int) $stats['rewards_earned'] ?></div><div class="l">Rewards ready to redeem</div></div>
    <div class="kpi"><div class="n"><?= (int) $stats['rewards_redeemed'] ?></div><div class="l">Rewards redeemed</div></div>
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
      <label>Require joining the group</label>
      <select name="require_group_join">
        <option value="1" <?= (string) $ref->setting('referral_require_group_join','1')==='1'?'selected':'' ?>>On - referral counts only after the friend joins the group</option>
        <option value="0" <?= (string) $ref->setting('referral_require_group_join','1')==='0'?'selected':'' ?>>Off - counts on registration alone</option>
      </select>
      <div class="mini">When on, an invited friend must join your Telegram group before the invite counts. Set the group and its invite link on the Schedule Settings page. The bot must be an admin in the group so it can confirm the join.</div>
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
      <div class="row" style="margin-top:8px">
        <div class="f"><label>Grants this promotion when redeemed</label>
          <select name="fulfill_package">
            <?php foreach ($HL_FULFILL_PACKAGES as $pk => $plabel): ?>
              <option value="<?= h($pk) ?>" <?= (string) ($t['fulfill_package'] ?? '') === (string) $pk ? 'selected' : '' ?>><?= h($plabel) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="mini">When a user redeems this reward, the bot sets up this promotion for them (no payment) and schedules it. Choose "Manual" for bundles the team arranges by hand.</div>
        </div>
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
      <div class="f"><label>Grants when redeemed</label>
        <select name="fulfill_package">
          <?php foreach ($HL_FULFILL_PACKAGES as $pk => $plabel): ?>
            <option value="<?= h($pk) ?>"><?= h($plabel) ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="f" style="margin-top:8px"><label>Reward content</label><textarea name="body" placeholder="One benefit per line"></textarea></div>
    <div style="margin-top:8px"><button class="btn sm" type="submit">Add tier</button></div>
  </form>
</div>

<div class="card">
  <div class="hd"><h2>Gift a Reward</h2></div>
  <p class="sub">Optionally give a user a reward without them inviting anyone - for example a goodwill gesture. It appears in their bot as "ready to redeem", and they pick a date/time to schedule it just like any other reward. This is a gift, not an approval step.</p>
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
  <p class="sub">Referrals are verified and counted automatically - once the invited friend joins the group (and any settle window passes), the invite qualifies on its own.</p>
  <div class="tblwrap"><table>
    <thead><tr><th>Invited friend</th><th>Invited by</th><th>Status</th><th>When</th></tr></thead>
    <tbody>
    <?php foreach ($referrals as $r): list($pc, $pl) = inv_status_pill($r['status']); ?>
      <tr>
        <td><b><?= h($r['referred_name'] ?: ('#' . $r['referred_id'])) ?></b><span class="mini">ID <?= (int) $r['referred_id'] ?></span></td>
        <td><?= h($r['referrer_name'] ?: ('#' . $r['referrer_id'])) ?><span class="mini">ID <?= (int) $r['referrer_id'] ?></span></td>
        <td><span class="pill <?= $pc ?>"><?= h($pl) ?></span>
            <?= $r['flagged'] ? '<span class="pill rej" title="' . h((string) $r['flag_reason']) . '">Flagged</span>' : '' ?>
            <?php if ($needJoin): ?>
              <?= (int) ($r['group_joined'] ?? 0) === 1
                    ? '<span class="pill ok" title="Confirmed member of the group">In group</span>'
                    : '<span class="pill mut" title="Has not joined the group yet">Not in group</span>' ?>
            <?php endif; ?></td>
        <td class="mini"><?= h($r['created_at']) ?></td>
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
