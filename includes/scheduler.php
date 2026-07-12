<?php
/**
 * scheduler.php - the scheduling + auto-posting engine for HabeshaList.
 *
 * Self-contained: it opens its OWN SQLite3 handle to the same bot.sqlite (so the
 * live bot's database.php is untouched) and uses the shared Telegram class to
 * post. Driven by scheduler.php in the project root, run every few minutes by
 * cron. Three jobs each run:
 *   1) bookApproved()  - turn newly-approved promotions into slot bookings.
 *   2) postDue()       - post any booking whose slot time has arrived, pin it.
 *   3) unpinExpired()  - unpin posts whose pin window has passed.
 *
 * Slots: three per day (morning/lunch/evening), max one post per slot, so at
 * most 3 posts a day. Times + timezone + target group are admin-editable
 * settings (sched_slot_*, sched_tz, sched_group_chat_id).
 */

class HL_Scheduler {
    private $db;
    private $tg;
    private $config;
    public $log = [];

    const DEFAULTS = [
        'sched_group_chat_id' => '-1003547700792',
        'sched_tz'            => 'America/New_York',
        'sched_slot_morning'  => '08:30',
        'sched_slot_lunch'    => '12:30',
        'sched_slot_evening'  => '19:30',
        'sched_enabled'       => '1',
    ];

    public function __construct($dbPath, Telegram $tg, array $config) {
        $this->db = new SQLite3($dbPath);
        $this->db->busyTimeout(8000);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->tg = $tg;
        $this->config = $config;
        $this->ensureTable();
    }

    private function ensureTable() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS scheduled_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                promotion_id INTEGER NOT NULL,
                business_name TEXT,
                package_key TEXT,
                post_date TEXT NOT NULL,        -- YYYY-MM-DD in the schedule timezone
                slot TEXT NOT NULL,             -- morning | lunch | evening
                pin INTEGER DEFAULT 0,
                pin_hours INTEGER DEFAULT 0,
                status TEXT DEFAULT 'scheduled',-- scheduled | posted | failed | canceled
                tg_message_id INTEGER,
                pin_until TEXT,                 -- UTC 'Y-m-d H:i:s' while pinned
                posted_at TEXT,
                error TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    // ---- settings helpers ----
    public function getSetting($key) {
        $stmt = $this->db->prepare('SELECT value FROM settings WHERE key = :k');
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($row && $row['value'] !== '' && $row['value'] !== null) return $row['value'];
        return self::DEFAULTS[$key] ?? '';
    }
    private function tz() {
        try { return new DateTimeZone($this->getSetting('sched_tz')); }
        catch (\Throwable $e) { return new DateTimeZone('America/New_York'); }
    }
    private function slotTimes() {
        return [
            'morning' => $this->getSetting('sched_slot_morning'),
            'lunch'   => $this->getSetting('sched_slot_lunch'),
            'evening' => $this->getSetting('sched_slot_evening'),
        ];
    }
    private function pkg($key) {
        return $this->config['promo_packages'][$key] ?? [];
    }

    // ---- main entry ----
    public function run() {
        if ($this->getSetting('sched_enabled') !== '1') {
            $this->log[] = 'scheduler disabled (sched_enabled != 1)';
            return $this->log;
        }
        $this->bookApproved();
        $this->postDue();
        $this->unpinExpired();
        return $this->log;
    }

    // ---- 1) booking ----
    public function bookApproved() {
        $res = $this->db->query("
            SELECT * FROM promotions
            WHERE status='approved'
              AND id NOT IN (SELECT DISTINCT promotion_id FROM scheduled_posts)
            ORDER BY id ASC");
        $promos = [];
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $promos[] = $r; }
        foreach ($promos as $p) { $this->bookPromo($p); }
    }

    private function slotFree($date, $slot) {
        $stmt = $this->db->prepare("SELECT COUNT(*) c FROM scheduled_posts
            WHERE post_date=:d AND slot=:s AND status IN ('scheduled','posted')");
        $stmt->bindValue(':d', $date, SQLITE3_TEXT);
        $stmt->bindValue(':s', $slot, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return ((int) ($row['c'] ?? 0)) === 0;
    }
    private function botwWeekTaken($isoWeek, $exceptPromoId) {
        // Compare ISO weeks in PHP (SQLite's %W is not the ISO week).
        $stmt = $this->db->prepare("SELECT post_date FROM scheduled_posts
            WHERE package_key='botw' AND promotion_id<>:pid AND status IN ('scheduled','posted')");
        $stmt->bindValue(':pid', $exceptPromoId, SQLITE3_INTEGER);
        $res = $stmt->execute();
        $tz = $this->tz();
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
            $d = DateTime::createFromFormat('Y-m-d', $r['post_date'], $tz);
            if ($d instanceof DateTime && $d->format('o-W') === $isoWeek) return true;
        }
        return false;
    }

    private function bookPromo($promo) {
        $key = $promo['package_key'] ?: 'one_time';
        $pkg = $this->pkg($key);
        $total = (int) ($promo['posts_total'] ?: ($pkg['posts_total'] ?? 1));
        $used = (int) ($promo['posts_used'] ?? 0);
        $toPlace = max(0, $total - $used);
        if ($toPlace <= 0) { $this->log[] = "promo {$promo['id']}: nothing to place"; return; }

        $perWeek = $pkg['posts_per_week'] ?? null;   // null => no weekly cap
        $duration = (int) ($pkg['duration_days'] ?? 0);
        $horizon = $duration > 0 ? $duration + 5 : 60; // days to search
        $slots = ['morning', 'lunch', 'evening'];

        $tz = $this->tz();
        $today = new DateTime('now', $tz);
        $today->setTime(0, 0);
        $start = clone $today;
        if (!empty($promo['start_date'])) {
            $sd = DateTime::createFromFormat('Y-m-d', substr($promo['start_date'], 0, 10), $tz);
            if ($sd instanceof DateTime && $sd > $start) { $start->setTimestamp($sd->getTimestamp()); }
        }

        // Business of the Week: exactly one exclusive, pinned post; holds the week.
        if ($key === 'botw') {
            $cur = clone $start;
            for ($d = 0; $d < $horizon; $d++) {
                $date = $cur->format('Y-m-d');
                $isoWeek = $cur->format('o-W');
                if (!$this->botwWeekTaken($isoWeek, $promo['id'])) {
                    foreach ($slots as $slot) {
                        if ($this->slotFree($date, $slot)) {
                            $this->insertBooking($promo, $date, $slot, 1, 24 * 7);
                            $this->log[] = "promo {$promo['id']} (botw): booked {$date} {$slot}, pinned 7d";
                            return;
                        }
                    }
                }
                $cur->modify('+1 day');
            }
            $this->log[] = "promo {$promo['id']} (botw): no free exclusive week found in horizon";
            return;
        }

        $placed = 0;
        $weekCount = [];        // 'Y-W' => count placed for THIS promo
        $monthsPinned = [];     // 'Y-m' => already placed a pin this month (yearly)
        $cur = clone $start;
        for ($d = 0; $d < $horizon && $placed < $toPlace; $d++) {
            $date = $cur->format('Y-m-d');
            $wk = $cur->format('o-W');
            $month = $cur->format('Y-m');
            if ($perWeek !== null && ($weekCount[$wk] ?? 0) >= $perWeek) { $cur->modify('+1 day'); continue; }
            foreach ($slots as $slot) {
                if ($placed >= $toPlace) break;
                if ($perWeek !== null && ($weekCount[$wk] ?? 0) >= $perWeek) break;
                if (!$this->slotFree($date, $slot)) continue;

                // pin rules
                $pin = 0; $pinHours = 0;
                if ($key === 'monthly' && $placed === 0) { $pin = 1; $pinHours = 24; }
                if ($key === 'yearly' && empty($monthsPinned[$month])) { $pin = 1; $pinHours = 24; $monthsPinned[$month] = true; }

                $this->insertBooking($promo, $date, $slot, $pin, $pinHours);
                $placed++;
                $weekCount[$wk] = ($weekCount[$wk] ?? 0) + 1;
                break; // at most one post per day for a given promo, so posts stay spread out
            }
            $cur->modify('+1 day');
        }
        $this->log[] = "promo {$promo['id']} ({$key}): booked {$placed}/{$toPlace} posts";
    }

    private function insertBooking($promo, $date, $slot, $pin, $pinHours) {
        $stmt = $this->db->prepare("
            INSERT INTO scheduled_posts (promotion_id, business_name, package_key, post_date, slot, pin, pin_hours, status)
            VALUES (:pid, :bn, :pk, :d, :s, :pin, :ph, 'scheduled')");
        $stmt->bindValue(':pid', (int) $promo['id'], SQLITE3_INTEGER);
        $stmt->bindValue(':bn', $promo['business_name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':pk', $promo['package_key'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':d', $date, SQLITE3_TEXT);
        $stmt->bindValue(':s', $slot, SQLITE3_TEXT);
        $stmt->bindValue(':pin', (int) $pin, SQLITE3_INTEGER);
        $stmt->bindValue(':ph', (int) $pinHours, SQLITE3_INTEGER);
        $stmt->execute();
    }

    // ---- 2) posting due bookings ----
    public function postDue() {
        $tz = $this->tz();
        $now = new DateTime('now', $tz);
        $slotTimes = $this->slotTimes();
        $chat = $this->getSetting('sched_group_chat_id');

        $res = $this->db->query("SELECT * FROM scheduled_posts WHERE status='scheduled' ORDER BY post_date ASC, id ASC");
        $due = [];
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
            $hm = $slotTimes[$r['slot']] ?? '00:00';
            $when = DateTime::createFromFormat('Y-m-d H:i', $r['post_date'] . ' ' . $hm, $tz);
            if ($when instanceof DateTime && $when <= $now) { $due[] = $r; }
        }
        foreach ($due as $r) { $this->postOne($r, $chat); }
    }

    private function postOne($row, $chat) {
        $stmt = $this->db->prepare('SELECT * FROM promotions WHERE id = :id');
        $stmt->bindValue(':id', (int) $row['promotion_id'], SQLITE3_INTEGER);
        $promo = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$promo) { $this->markFailed($row['id'], 'promotion missing'); return; }

        $text = $this->buildPostText($promo);
        $logo = $promo['logo'] ?? '';
        $images = [];
        if (!empty($promo['images'])) {
            $decoded = json_decode($promo['images'], true);
            if (is_array($decoded)) $images = $decoded;
        }

        // Prefer a single photo (logo) with caption so we can pin that message.
        if ($logo !== '') {
            $resp = $this->tg->sendPhoto($chat, $logo, $text);
        } elseif (!empty($images)) {
            $this->tg->sendMediaGroup($chat, $images);
            $resp = $this->tg->sendMessage($chat, $text);
        } else {
            $resp = $this->tg->sendMessage($chat, $text);
        }

        if (empty($resp['ok']) || empty($resp['result']['message_id'])) {
            $this->markFailed($row['id'], $resp['description'] ?? 'send failed');
            return;
        }
        $mid = (int) $resp['result']['message_id'];

        $pinUntil = null;
        if ((int) $row['pin'] === 1) {
            $p = $this->tg->callApi('pinChatMessage', ['chat_id' => $chat, 'message_id' => $mid, 'disable_notification' => true]);
            if (!empty($p['ok'])) {
                $hrs = (int) $row['pin_hours'] ?: 24;
                $pinUntil = gmdate('Y-m-d H:i:s', time() + $hrs * 3600);
            }
        }

        $u = $this->db->prepare("UPDATE scheduled_posts
            SET status='posted', tg_message_id=:m, posted_at=:t, pin_until=:pu, error=NULL WHERE id=:id");
        $u->bindValue(':m', $mid, SQLITE3_INTEGER);
        $u->bindValue(':t', gmdate('Y-m-d H:i:s'), SQLITE3_TEXT);
        $u->bindValue(':pu', $pinUntil, $pinUntil === null ? SQLITE3_NULL : SQLITE3_TEXT);
        $u->bindValue(':id', (int) $row['id'], SQLITE3_INTEGER);
        $u->execute();

        // bump posts_used on the promotion
        $this->db->exec('UPDATE promotions SET posts_used = COALESCE(posts_used,0) + 1 WHERE id = ' . (int) $row['promotion_id']);

        // let the business owner know it went live
        if (!empty($promo['telegram_id'])) {
            $bn = $promo['business_name'] ?: 'your business';
            $this->tg->sendMessage((int) $promo['telegram_id'],
                "\xF0\x9F\x93\xA2 <b>Your promotion is live!</b>\n\n<b>" . htmlspecialchars($bn, ENT_QUOTES) .
                "</b> was just posted in the HabeshaList group" . ((int) $row['pin'] === 1 ? " and pinned to the top" : "") . ". Thank you!");
        }
        $this->log[] = "posted scheduled #{$row['id']} (promo {$row['promotion_id']}) msg {$mid}" . ($pinUntil ? ' + pinned' : '');
    }

    private function markFailed($id, $err) {
        $u = $this->db->prepare("UPDATE scheduled_posts SET status='failed', error=:e WHERE id=:id");
        $u->bindValue(':e', substr($err, 0, 300), SQLITE3_TEXT);
        $u->bindValue(':id', (int) $id, SQLITE3_INTEGER);
        $u->execute();
        $this->log[] = "FAILED scheduled #{$id}: {$err}";
    }

    // ---- 3) unpin expired ----
    public function unpinExpired() {
        $chat = $this->getSetting('sched_group_chat_id');
        $nowUtc = gmdate('Y-m-d H:i:s');
        $res = $this->db->query("SELECT * FROM scheduled_posts
            WHERE status='posted' AND pin_until IS NOT NULL AND pin_until < '" . SQLite3::escapeString($nowUtc) . "'");
        $rows = [];
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $rows[] = $r; }
        foreach ($rows as $r) {
            if (!empty($r['tg_message_id'])) {
                $this->tg->callApi('unpinChatMessage', ['chat_id' => $chat, 'message_id' => (int) $r['tg_message_id']]);
            }
            $u = $this->db->prepare("UPDATE scheduled_posts SET pin_until=NULL WHERE id=:id");
            $u->bindValue(':id', (int) $r['id'], SQLITE3_INTEGER);
            $u->execute();
            $this->log[] = "unpinned scheduled #{$r['id']}";
        }
    }

    // ---- post text ----
    private function buildPostText($promo) {
        $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
        $lines = [];
        $lines[] = "\xF0\x9F\x93\xA2 <b>" . $e($promo['business_name'] ?: 'Featured Business') . "</b>";
        if (!empty($promo['business_category'])) $lines[] = $e($promo['business_category']);
        $lines[] = '';
        if (!empty($promo['description'])) { $lines[] = $e($promo['description']); $lines[] = ''; }
        if (!empty($promo['phone']))   $lines[] = "\xF0\x9F\x93\x9E " . $e($promo['phone']);
        if (!empty($promo['website'])) $lines[] = "\xF0\x9F\x8C\x90 " . $e($promo['website']);
        if (!empty($promo['social']))  $lines[] = "\xF0\x9F\x94\x97 " . $e($promo['social']);
        if (!empty($promo['address'])) $lines[] = "\xF0\x9F\x93\x8D " . $e($promo['address']);
        if (!empty($promo['hours']))   $lines[] = "\xF0\x9F\x95\x92 " . $e($promo['hours']);
        if (!empty($promo['cta']))     { $lines[] = ''; $lines[] = "\xF0\x9F\x91\x89 " . $e($promo['cta']); }
        return trim(implode("\n", $lines));
    }
}
