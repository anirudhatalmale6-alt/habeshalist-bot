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

    if ($form === 'group') {
        // Community display name (shown to users in the bot).
        $groupName = trim($_POST['group_name'] ?? '');
        hl_set_setting('group_name', $groupName);
        $flash = 'Group name saved. The bot uses it on its next run.';
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
            hl_set_setting('sched_group_chat_id', $group);
            hl_set_setting('sched_tz', $tz);
            foreach ($times as $sk => $v) hl_set_setting($sk, $v);
            hl_set_setting('sched_enabled', isset($_POST['sched_enabled']) ? '1' : '0');
            $flash = 'Schedule settings saved. The scheduler uses them on its next run.';
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
  <h2>Group name</h2>
  <p class="sub">The display name for your community, shown to users inside the bot (for example in the Invite &amp; Earn welcome).</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="form" value="group">

    <div class="row"><div class="field">
      <label>Group name</label>
      <input type="text" name="group_name" value="<?= h(hl_get_setting('group_name', 'HabeshaList')) ?>" placeholder="HabeshaList" spellcheck="false">
      <div class="muted small" style="margin-top:5px">Used wherever the bot mentions your community by name.</div>
    </div></div>

    <button type="submit">Save group name</button>
  </form>
</div>

<div class="card">
  <h2>How to turn it on</h2>
  <p class="sub">The scheduler runs from a cron job (I will give you the exact one-line command for your server). It books approved promotions into free slots and posts them to the group at each slot time, pinning where the plan includes a pin.</p>
  <p class="muted small mono">*/5 * * * * php /home/USER/.../bot/scheduler.php &gt;&gt; /home/USER/.../bot/data/scheduler.log 2&gt;&amp;1</p>
</div>

<?php hl_shell_foot();
