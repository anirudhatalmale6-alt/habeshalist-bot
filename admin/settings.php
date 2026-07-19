<?php
/**
 * settings.php - schedule settings: target group, timezone, the three slot
 * times, and an on/off switch. All read live by the scheduler cron.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();

$flash = null; $flashType = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hl_csrf_check();
    $errs = [];
    $form = $_POST['form'] ?? 'schedule';

    if ($form === 'gen_link') {
        // Ask Telegram to mint a real invite link for the configured group and
        // store it as THE controlled link. Surfaces the exact Telegram error
        // (wrong chat id / missing "invite via link" permission) if it can't.
        $chat = trim(hl_get_setting('sched_group_chat_id', HL_SCHED['sched_group_chat_id']['default']));
        $token = hl_effective_secret('TELEGRAM_BOT_TOKEN', 'sec_bot_token');
        if ($chat === '') {
            $flash = 'Set the Telegram group (chat id or @username) above and save it first, then generate the link.'; $flashType = 'err';
        } elseif ($token === '') {
            $flash = 'The bot token is not available to the panel here, so it cannot generate a link. Paste a t.me/+ invite link manually instead.'; $flashType = 'err';
        } else {
            $link = ''; $err = '';
            foreach (['createChatInviteLink', 'exportChatInviteLink'] as $m) {
                $p = ['chat_id' => $chat];
                if ($m === 'createChatInviteLink') $p['name'] = 'Invite & Earn';
                $r = hl_tg_api($token, $m, $p);
                if (!empty($r['ok'])) {
                    $link = is_array($r['result'] ?? null) ? (string) ($r['result']['invite_link'] ?? '') : (string) ($r['result'] ?? '');
                    if ($link !== '') break;
                } else {
                    $err = (string) ($r['description'] ?? 'unknown error');
                }
            }
            if ($link !== '' && preg_match('#^https?://#i', $link)) {
                hl_set_setting('group_invite_link', $link);
                hl_set_setting('group_invite_link_auto', $link);
                hl_set_setting('group_invite_link_auto_chat', $chat);
                $flash = 'Done! A working join link was generated and saved: ' . $link . ' - open it on your phone to test, then try the bot\'s Join button.';
            } else {
                $hint = (stripos($err, 'invite') !== false || stripos($err, 'rights') !== false || stripos($err, 'admin') !== false)
                    ? ' Fix: in Telegram, open the group, promote the bot to admin and turn ON the "Invite Users via Link" permission, then try again.'
                    : ((stripos($err, 'not found') !== false || stripos($err, 'chat not') !== false)
                        ? ' Fix: the group chat id above looks wrong. Add the bot to the group and use that group\'s exact chat id.'
                        : '');
                $flash = 'Telegram would not generate a link: ' . ($err !== '' ? $err : 'unknown error') . '.' . $hint; $flashType = 'err';
            }
        }
    } elseif ($form === 'group') {
        // Community display name + public join link (shown to users in the bot).
        $groupName = trim($_POST['group_name'] ?? '');
        $groupLink = trim($_POST['group_invite_link'] ?? '');
        if ($groupLink !== '' && !preg_match('#^https?://#i', $groupLink)) {
            $errs[] = 'Group invite link must be a full link starting with https:// (e.g. https://t.me/+AbCdEf...).';
        }
        if ($errs) {
            $flash = implode(' ', $errs); $flashType = 'err';
        } else {
            hl_set_setting('group_name', $groupName);
            hl_set_setting('group_invite_link', $groupLink);
            $flash = 'Group settings saved. The bot uses them on its next run.';
        }
    } else {
        $group = trim($_POST['sched_group_chat_id'] ?? '');
        if ($group === '') $errs[] = 'Group chat id / username cannot be empty.';

        $tz = trim($_POST['sched_tz'] ?? '');
        if (!in_array($tz, timezone_identifiers_list(), true)) $errs[] = 'That timezone is not recognised.';

        $times = [];
        foreach (['sched_slot_morning', 'sched_slot_lunch', 'sched_slot_evening'] as $sk) {
            $v = trim($_POST[$sk] ?? '');
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) $errs[] = HL_SCHED[$sk]['label'] . ' must be a 24h time like 08:30.';
            $times[$sk] = $v;
        }

        if ($errs) {
            $flash = implode(' ', $errs); $flashType = 'err';
        } else {
            $prevGroup = trim(hl_get_setting('sched_group_chat_id', HL_SCHED['sched_group_chat_id']['default']));
            hl_set_setting('sched_group_chat_id', $group);
            hl_set_setting('sched_tz', $tz);
            foreach ($times as $sk => $v) hl_set_setting($sk, $v);
            hl_set_setting('sched_enabled', isset($_POST['sched_enabled']) ? '1' : '0');
            $flash = 'Schedule settings saved. The scheduler uses them on its next run.';
            // Switched to a different group? Drop the cached auto invite link so a
            // stale link for the old (test) group is never shown for the new one.
            if ($group !== $prevGroup) {
                hl_set_setting('group_invite_link_auto', '');
                hl_set_setting('group_invite_link_auto_chat', '');
                $flash .= ' (Group changed - regenerate the join link below for the new group.)';
            }
        }
    }
}

$enabled = hl_get_setting('sched_enabled', '1') === '1';
$csrf = h(hl_csrf_token());
hl_shell_head('Schedule Settings', 'schedule', hl_pending_count());
if ($flash) hl_flash($flash, $flashType);
?>

<div class="card">
  <h2>Auto-posting schedule</h2>
  <p class="sub">Where promotions post, in which timezone, and at what times. Three slots a day, one post per slot. Changes apply on the scheduler's next run.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="form" value="schedule">

    <div class="row"><div class="field">
      <label><?= h(HL_SCHED['sched_group_chat_id']['label']) ?></label>
      <input type="text" name="sched_group_chat_id" value="<?= h(hl_sched('sched_group_chat_id')) ?>" spellcheck="false">
      <div class="muted small" style="margin-top:5px">The bot must be an admin in this group with permission to post and pin.</div>
    </div></div>

    <div class="row"><div class="field">
      <label><?= h(HL_SCHED['sched_tz']['label']) ?></label>
      <input type="text" name="sched_tz" value="<?= h(hl_sched('sched_tz')) ?>" list="tzlist" spellcheck="false">
      <datalist id="tzlist">
        <?php foreach (['America/New_York','America/Chicago','America/Denver','America/Los_Angeles','Africa/Addis_Ababa','Europe/London','Asia/Dubai'] as $z): ?>
          <option value="<?= h($z) ?>"></option>
        <?php endforeach; ?>
      </datalist>
    </div></div>

    <div class="row">
      <div class="field"><label>Morning slot</label>
        <input type="text" name="sched_slot_morning" value="<?= h(hl_sched('sched_slot_morning')) ?>" placeholder="08:30"></div>
      <div class="field"><label>Lunch slot</label>
        <input type="text" name="sched_slot_lunch" value="<?= h(hl_sched('sched_slot_lunch')) ?>" placeholder="12:30"></div>
      <div class="field"><label>Evening slot</label>
        <input type="text" name="sched_slot_evening" value="<?= h(hl_sched('sched_slot_evening')) ?>" placeholder="19:30"></div>
    </div>

    <div class="row"><div class="field">
      <label style="display:flex;align-items:center;gap:9px;cursor:pointer">
        <input type="checkbox" name="sched_enabled" value="1" <?= $enabled ? 'checked' : '' ?> style="width:auto">
        <span>Auto-posting enabled</span>
      </label>
      <div class="muted small" style="margin-top:5px">Turn this off to pause all automatic posting without touching the cron job.</div>
    </div></div>

    <button type="submit">Save schedule settings</button>
  </form>
</div>

<div class="card">
  <h2>Group name &amp; invite link</h2>
  <p class="sub">Your community's display name and the public link people tap to join your Telegram group. The join link is shown to a newly-referred user so they can complete the "join the group" step of Invite &amp; Earn.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="form" value="group">

    <div class="row"><div class="field">
      <label>Group name</label>
      <input type="text" name="group_name" value="<?= h(hl_get_setting('group_name', 'HabeshaList')) ?>" placeholder="HabeshaList" spellcheck="false">
      <div class="muted small" style="margin-top:5px">Used wherever the bot mentions your community by name.</div>
    </div></div>

    <div class="row"><div class="field">
      <label>Group invite link</label>
      <input type="text" name="group_invite_link" value="<?= h(hl_get_setting('group_invite_link', '')) ?>" placeholder="https://t.me/+AbCdEf... (or leave blank)" spellcheck="false">
      <div class="muted small" style="margin-top:5px">
        Best to <b>leave this blank</b> &ndash; the bot will generate a working join link automatically (the bot just needs to be an admin of the group with the "Invite Users via Link" right).
        If you do paste one, use the group's <b>Invite Link</b> (looks like <span class="mono">https://t.me/+&hellip;</span>), not a message link. Links like <span class="mono">t.me/c/&hellip;</span> cause "This group is unavailable".
      </div>
      <?php
        $glink = trim(hl_get_setting('group_invite_link', ''));
        $glOk = $glink === '' ||
            preg_match('#^https?://t\.me/(\+[A-Za-z0-9_-]+|joinchat/[A-Za-z0-9_-]+|[A-Za-z][A-Za-z0-9_]{3,})$#i', $glink);
        if ($glink !== '' && !$glOk):
      ?>
        <div class="small" style="margin-top:6px;color:#b45309">
          &#9888; This doesn't look like a join link, so the bot is ignoring it and using an auto-generated one instead. Clear this field, or replace it with a proper <span class="mono">https://t.me/+&hellip;</span> invite link.
        </div>
      <?php endif; ?>
    </div></div>

    <button type="submit">Save group settings</button>
  </form>

  <?php
    // Work out which link the bot will actually hand to users right now, so the
    // client can see and test it - mirrors webhook.php groupJoinLink().
    $joinRe = '#^https?://t\.me/(\+[A-Za-z0-9_-]+|joinchat/[A-Za-z0-9_-]+|[A-Za-z][A-Za-z0-9_]{3,})$#i';
    $chatNow   = trim(hl_get_setting('sched_group_chat_id', HL_SCHED['sched_group_chat_id']['default']));
    $manual    = trim(hl_get_setting('group_invite_link', ''));
    $autoLink  = trim(hl_get_setting('group_invite_link_auto', ''));
    $autoChat  = trim(hl_get_setting('group_invite_link_auto_chat', ''));
    $active = '';
    if (preg_match($joinRe, $manual)) $active = $manual;
    elseif (preg_match($joinRe, $autoLink) && $autoChat !== '' && $autoChat === $chatNow) $active = $autoLink;
  ?>
  <div class="row" style="margin-top:14px;padding-top:14px;border-top:1px solid rgba(128,128,128,.2)">
    <div class="field">
      <label>Generate a working link automatically</label>
      <div class="muted small" style="margin-bottom:8px">
        Recommended way to control this. Set the group above (the <b><?= h(HL_SCHED['sched_group_chat_id']['label']) ?></b> in the schedule card), then press the button &ndash; the bot asks Telegram for a real join link for that exact group and saves it here. Switching between your test and live groups is just: change the group id, save, then press this again.
      </div>
      <?php if ($active !== ''): ?>
        <div class="small" style="margin-bottom:8px">Active join link the bot is using now: <a href="<?= h($active) ?>" target="_blank" rel="noopener" class="mono"><?= h($active) ?></a> <span class="muted">(tap to test it yourself)</span></div>
      <?php else: ?>
        <div class="small" style="margin-bottom:8px;color:#b45309">&#9888; No working join link is set yet for the current group. Press the button below to create one.</div>
      <?php endif; ?>
      <form method="post" style="margin:0">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="form" value="gen_link">
        <button type="submit">Generate &amp; save join link</button>
      </form>
    </div>
  </div>
</div>

<div class="card">
  <h2>How to turn it on</h2>
  <p class="sub">The scheduler runs from a cron job (I will give you the exact one-line command for your server). It books approved promotions into free slots and posts them to the group at each slot time, pinning where the plan includes a pin.</p>
  <p class="muted small mono">*/5 * * * * php /home/USER/.../bot/scheduler.php &gt;&gt; /home/USER/.../bot/data/scheduler.log 2&gt;&amp;1</p>
</div>

<?php hl_shell_foot();
