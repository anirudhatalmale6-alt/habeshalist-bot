<?php
/**
 * soon.php - placeholder for roadmap items (scheduling calendar, subscriptions,
 * reports) that are their own upcoming milestone.
 */
require __DIR__ . '/lib.php';
require __DIR__ . '/view.php';
hl_require_login();

hl_shell_head('Coming soon', '', hl_pending_count());
?>
<div class="card">
  <h2>Coming in the next milestone</h2>
  <p class="sub">This section is planned but not built yet.</p>
  <p>Scheduled Posts, the Calendar &amp; Daily Slots booking (Morning / Lunch / Evening, max 3 posts a day), Subscriptions with auto-reset, and Reports all ride on the scheduling &amp; auto-posting engine. That is the next milestone after this dashboard.</p>
  <p class="muted small">Everything else in the sidebar is live and working now.</p>
  <p><a class="btn" href="index.php">Back to dashboard</a></p>
</div>
<?php hl_shell_foot();
