<?php
/**
 * referral.php - the "Invite & Earn" referral + rewards engine for HabeshaList.
 *
 * Self-contained like HL_Scheduler: it opens its OWN SQLite3 handle to the same
 * bot.sqlite so both the bot (webhook.php) and the admin panel can use the exact
 * same logic. All referral / reward / audit data lives here.
 *
 * Flow (mirrors the client's Invite & Earn mockup):
 *   1. Every user gets a unique referral code + t.me deep link.
 *   2. A NEW user who registers via someone's link is attributed to that inviter
 *      (one inviter per user, no self-referral, duplicate accounts flagged).
 *   3. A referral becomes "qualified" after a configurable settle window
 *      (default 7 days) - this is the "stayed active" gate. Qualified referrals
 *      count toward reward milestones.
 *   4. When a user's qualified count reaches a reward tier, a reward is created
 *      as "earned" (pending admin approval). Admin approves (with start/end
 *      dates) or rejects; admin can also grant a reward manually.
 *   5. Every change is written to an audit log.
 */

class HL_Referral {
    private $db;
    public $log = [];

    // Default reward tiers, seeded once. Straight from the client's mockup.
    const DEFAULT_TIERS = [
        [20,  '1 Month Telegram Promotion',            "2 promotional posts per week for 1 month."],
        [50,  '1 Week Premium Business of the Week',   "Featured and pinned in the group for 7 days."],
        [100, 'Business Growth Package',               "3 Months Telegram Promotion\n1 Month Business of the Week\nHomepage Featured Listing\nOne Promotional Video/Reel"],
    ];

    const DEFAULTS = [
        'invite_earn_enabled'   => '1',   // feature master switch
        'referral_qualify_days' => '7',   // days a referral must settle before it counts
        'referral_usa_only'     => '0',   // informational gate shown in the bot copy
    ];

    public function __construct($dbPath) {
        $this->db = new SQLite3($dbPath);
        $this->db->busyTimeout(5000);
        @$this->db->exec('PRAGMA journal_mode = WAL;');
        $this->ensureSchema();
    }

    // ---------------------------------------------------------------------
    // Schema
    // ---------------------------------------------------------------------
    public function ensureSchema() {
        $this->db->exec("CREATE TABLE IF NOT EXISTS referrals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            referrer_id INTEGER NOT NULL,
            referred_id INTEGER NOT NULL UNIQUE,
            referred_name TEXT,
            status TEXT NOT NULL DEFAULT 'registered',   -- registered | qualified | rejected
            flagged INTEGER NOT NULL DEFAULT 0,
            flag_reason TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            registered_at DATETIME,
            qualifies_at DATETIME,
            qualified_at DATETIME
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS referral_tiers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invites_required INTEGER NOT NULL,
            title TEXT NOT NULL,
            body TEXT,
            sort INTEGER DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS referral_rewards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            telegram_id INTEGER NOT NULL,
            tier_id INTEGER,
            tier_invites INTEGER,
            title TEXT NOT NULL,
            body TEXT,
            status TEXT NOT NULL DEFAULT 'earned',   -- earned | approved | rejected
            source TEXT NOT NULL DEFAULT 'auto',      -- auto | manual
            start_date TEXT,
            end_date TEXT,
            notes TEXT,
            decided_by INTEGER,
            decided_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $this->db->exec("CREATE TABLE IF NOT EXISTS referral_audit (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ts DATETIME DEFAULT CURRENT_TIMESTAMP,
            actor TEXT,           -- 'system' | 'admin:<id>' | 'user:<id>'
            event TEXT NOT NULL,
            telegram_id INTEGER,
            ref_id INTEGER,
            detail TEXT
        )");
        // users.referral_code (added if the users table pre-dates this feature).
        $cols = [];
        $res = $this->db->query("PRAGMA table_info(users)");
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $cols[] = $r['name']; }
        if ($cols && !in_array('referral_code', $cols, true)) {
            $this->db->exec("ALTER TABLE users ADD COLUMN referral_code TEXT");
        }
        // Seed default tiers once.
        $n = (int) $this->db->querySingle("SELECT COUNT(*) FROM referral_tiers");
        if ($n === 0) {
            $i = 0;
            foreach (self::DEFAULT_TIERS as $t) {
                $stmt = $this->db->prepare("INSERT INTO referral_tiers (invites_required,title,body,sort,active)
                                            VALUES (:inv,:ti,:bo,:so,1)");
                $stmt->bindValue(':inv', (int) $t[0], SQLITE3_INTEGER);
                $stmt->bindValue(':ti', $t[1], SQLITE3_TEXT);
                $stmt->bindValue(':bo', $t[2], SQLITE3_TEXT);
                $stmt->bindValue(':so', $i++, SQLITE3_INTEGER);
                $stmt->execute();
            }
        }
    }

    // ---------------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------------
    public function setting($key, $default = null) {
        $stmt = $this->db->prepare("SELECT value FROM settings WHERE key = :k");
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($row !== false && $row !== null) return $row['value'];
        if ($default !== null) return $default;
        return self::DEFAULTS[$key] ?? null;
    }
    public function setSetting($key, $value) {
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES (:k,:v)");
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $stmt->bindValue(':v', (string) $value, SQLITE3_TEXT);
        return $stmt->execute();
    }
    public function isEnabled() { return (string) $this->setting('invite_earn_enabled', self::DEFAULTS['invite_earn_enabled']) === '1'; }
    public function qualifyDays() { return max(0, (int) $this->setting('referral_qualify_days', self::DEFAULTS['referral_qualify_days'])); }

    // ---------------------------------------------------------------------
    // Referral codes
    // ---------------------------------------------------------------------
    // Get (or lazily create) the user's unique referral code.
    public function ensureCode($telegramId) {
        $stmt = $this->db->prepare("SELECT referral_code FROM users WHERE telegram_id = :id");
        $stmt->bindValue(':id', $telegramId, SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($row && !empty($row['referral_code'])) return $row['referral_code'];
        // Generate a unique 8-char A-Z0-9 code.
        for ($try = 0; $try < 20; $try++) {
            $code = $this->randomCode(8);
            $chk = $this->db->prepare("SELECT 1 FROM users WHERE referral_code = :c");
            $chk->bindValue(':c', $code, SQLITE3_TEXT);
            if ($chk->execute()->fetchArray(SQLITE3_ASSOC)) continue;   // collision, retry
            $up = $this->db->prepare("UPDATE users SET referral_code = :c WHERE telegram_id = :id");
            $up->bindValue(':c', $code, SQLITE3_TEXT);
            $up->bindValue(':id', $telegramId, SQLITE3_INTEGER);
            $up->execute();
            return $code;
        }
        return null;
    }
    private function randomCode($len) {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no ambiguous 0/O/1/I
        $out = '';
        for ($i = 0; $i < $len; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        return $out;
    }
    // The telegram id that owns a referral code, or null.
    public function codeOwner($code) {
        $code = strtoupper(trim((string) $code));
        if ($code === '') return null;
        $stmt = $this->db->prepare("SELECT telegram_id FROM users WHERE referral_code = :c");
        $stmt->bindValue(':c', $code, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $row ? (int) $row['telegram_id'] : null;
    }
    public function link($code, $botUsername) {
        return 'https://t.me/' . $botUsername . '?start=' . $code;
    }
    // A payload counts as a referral code if it is not one of the bot's own
    // action keywords and matches the code shape.
    public function looksLikeCode($payload) {
        $payload = strtoupper(trim((string) $payload));
        return (bool) preg_match('/^[A-Z0-9]{6,12}$/', $payload);
    }

    // ---------------------------------------------------------------------
    // Attribution (called when a NEW user finishes registration)
    // ---------------------------------------------------------------------
    // Returns [bool ok, string message]. Records the referral (registered) with a
    // qualify window, running anti-fraud checks. Never throws.
    public function attribute($referredId, $code, $referredName = '', $referredPhone = '', $referredEmail = '') {
        try {
            if (!$this->isEnabled()) return [false, 'feature_off'];
            $referrerId = $this->codeOwner($code);
            if (!$referrerId) return [false, 'bad_code'];
            if ((int) $referrerId === (int) $referredId) return [false, 'self'];
            // One inviter per referred user (first wins).
            $chk = $this->db->prepare("SELECT id FROM referrals WHERE referred_id = :r");
            $chk->bindValue(':r', $referredId, SQLITE3_INTEGER);
            if ($chk->execute()->fetchArray(SQLITE3_ASSOC)) return [false, 'already_referred'];

            // Fraud heuristic: a referred user whose phone/email matches a DIFFERENT
            // existing account is flagged for admin review (still recorded, but does
            // not auto-qualify).
            $flagged = 0; $reason = null;
            if ($referredPhone !== '' || $referredEmail !== '') {
                $dup = $this->db->prepare("SELECT telegram_id FROM users
                    WHERE telegram_id <> :me AND ((:ph <> '' AND phone = :ph) OR (:em <> '' AND email = :em)) LIMIT 1");
                $dup->bindValue(':me', $referredId, SQLITE3_INTEGER);
                $dup->bindValue(':ph', $referredPhone, SQLITE3_TEXT);
                $dup->bindValue(':em', $referredEmail, SQLITE3_TEXT);
                if ($dup->execute()->fetchArray(SQLITE3_ASSOC)) { $flagged = 1; $reason = 'duplicate_contact'; }
            }

            $now = new DateTime('now', new DateTimeZone('UTC'));
            $qualAt = (clone $now)->modify('+' . $this->qualifyDays() . ' day');
            $stmt = $this->db->prepare("INSERT INTO referrals
                (referrer_id, referred_id, referred_name, status, flagged, flag_reason, registered_at, qualifies_at)
                VALUES (:rr, :rd, :nm, 'registered', :fl, :rs, :reg, :qa)");
            $stmt->bindValue(':rr', $referrerId, SQLITE3_INTEGER);
            $stmt->bindValue(':rd', $referredId, SQLITE3_INTEGER);
            $stmt->bindValue(':nm', $referredName, SQLITE3_TEXT);
            $stmt->bindValue(':fl', $flagged, SQLITE3_INTEGER);
            $stmt->bindValue(':rs', $reason, SQLITE3_TEXT);
            $stmt->bindValue(':reg', $now->format('Y-m-d H:i:s'), SQLITE3_TEXT);
            $stmt->bindValue(':qa', $qualAt->format('Y-m-d H:i:s'), SQLITE3_TEXT);
            $stmt->execute();
            $refId = $this->db->lastInsertRowID();
            $this->audit('user:' . $referredId, 'referral_created', $referrerId, $refId,
                'referred=' . $referredId . ($flagged ? ' FLAGGED:' . $reason : ''));
            return [true, $flagged ? 'flagged' : 'ok'];
        } catch (\Throwable $e) {
            return [false, 'error'];
        }
    }

    // Promote settled referrals registered -> qualified. Safe to call often.
    public function refreshQualifications() {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->db->prepare("UPDATE referrals SET status='qualified', qualified_at=:now
            WHERE status='registered' AND flagged=0 AND qualifies_at IS NOT NULL AND qualifies_at <= :now");
        $stmt->bindValue(':now', $now, SQLITE3_TEXT);
        $stmt->execute();
        return $this->db->changes();
    }

    // ---------------------------------------------------------------------
    // Counts + progress
    // ---------------------------------------------------------------------
    public function countJoined($telegramId) {   // people who registered via my link
        $stmt = $this->db->prepare("SELECT COUNT(*) c FROM referrals
            WHERE referrer_id = :id AND status IN ('registered','qualified')");
        $stmt->bindValue(':id', $telegramId, SQLITE3_INTEGER);
        return (int) ($stmt->execute()->fetchArray(SQLITE3_ASSOC)['c'] ?? 0);
    }
    public function countQualified($telegramId) {   // count toward rewards
        $stmt = $this->db->prepare("SELECT COUNT(*) c FROM referrals
            WHERE referrer_id = :id AND status = 'qualified'");
        $stmt->bindValue(':id', $telegramId, SQLITE3_INTEGER);
        return (int) ($stmt->execute()->fetchArray(SQLITE3_ASSOC)['c'] ?? 0);
    }
    public function tiers($activeOnly = true) {
        $sql = "SELECT * FROM referral_tiers" . ($activeOnly ? " WHERE active=1" : "") . " ORDER BY invites_required ASC, sort ASC";
        $res = $this->db->query($sql);
        $out = [];
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $out[] = $r; }
        return $out;
    }
    public function tier($id) {
        $stmt = $this->db->prepare("SELECT * FROM referral_tiers WHERE id=:id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
    }
    // Full progress snapshot for the bot's "My Progress" screen.
    public function progress($telegramId) {
        $this->refreshQualifications();
        $joined = $this->countJoined($telegramId);
        $qualified = $this->countQualified($telegramId);
        $tiers = $this->tiers(true);
        $next = null;
        foreach ($tiers as $t) {
            if ($qualified < (int) $t['invites_required']) { $next = $t; break; }
        }
        $needed = $next ? max(0, (int) $next['invites_required'] - $qualified) : 0;
        return [
            'joined'    => $joined,
            'qualified' => $qualified,
            'tiers'     => $tiers,
            'next'      => $next,
            'needed'    => $needed,
            'rewards'   => $this->rewardsFor($telegramId),
        ];
    }
    // People I referred (for referral history).
    public function history($telegramId, $limit = 30) {
        $stmt = $this->db->prepare("SELECT * FROM referrals WHERE referrer_id = :id
            ORDER BY created_at DESC LIMIT :l");
        $stmt->bindValue(':id', $telegramId, SQLITE3_INTEGER);
        $stmt->bindValue(':l', $limit, SQLITE3_INTEGER);
        $res = $stmt->execute();
        $out = [];
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $out[] = $r; }
        return $out;
    }

    // ---------------------------------------------------------------------
    // Rewards
    // ---------------------------------------------------------------------
    public function rewardsFor($telegramId) {
        $stmt = $this->db->prepare("SELECT * FROM referral_rewards WHERE telegram_id = :id
            ORDER BY created_at DESC");
        $stmt->bindValue(':id', $telegramId, SQLITE3_INTEGER);
        $res = $stmt->execute();
        $out = [];
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $out[] = $r; }
        return $out;
    }
    public function reward($id) {
        $stmt = $this->db->prepare("SELECT * FROM referral_rewards WHERE id=:id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
    }
    public function hasRewardForTier($telegramId, $tierId) {
        $stmt = $this->db->prepare("SELECT 1 FROM referral_rewards WHERE telegram_id=:id AND tier_id=:t LIMIT 1");
        $stmt->bindValue(':id', $telegramId, SQLITE3_INTEGER);
        $stmt->bindValue(':t', $tierId, SQLITE3_INTEGER);
        return (bool) $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }
    // After qualification, create reward rows for any newly-reached tier. Returns
    // the list of newly-earned reward rows (for notifying the user).
    public function checkMilestones($telegramId) {
        $this->refreshQualifications();
        $qualified = $this->countQualified($telegramId);
        $newly = [];
        foreach ($this->tiers(true) as $t) {
            if ($qualified >= (int) $t['invites_required'] && !$this->hasRewardForTier($telegramId, $t['id'])) {
                $stmt = $this->db->prepare("INSERT INTO referral_rewards
                    (telegram_id, tier_id, tier_invites, title, body, status, source)
                    VALUES (:id,:t,:inv,:ti,:bo,'earned','auto')");
                $stmt->bindValue(':id', $telegramId, SQLITE3_INTEGER);
                $stmt->bindValue(':t', $t['id'], SQLITE3_INTEGER);
                $stmt->bindValue(':inv', (int) $t['invites_required'], SQLITE3_INTEGER);
                $stmt->bindValue(':ti', $t['title'], SQLITE3_TEXT);
                $stmt->bindValue(':bo', $t['body'], SQLITE3_TEXT);
                $stmt->execute();
                $rid = $this->db->lastInsertRowID();
                $this->audit('system', 'reward_earned', $telegramId, $rid, $t['title'] . ' @ ' . $t['invites_required']);
                $newly[] = $this->reward($rid);
            }
        }
        return $newly;
    }

    // ---- Admin reward actions ----
    public function approveReward($rewardId, $adminId, $startDate = null, $endDate = null, $notes = null) {
        $stmt = $this->db->prepare("UPDATE referral_rewards
            SET status='approved', start_date=:sd, end_date=:ed, notes=:no, decided_by=:by, decided_at=CURRENT_TIMESTAMP
            WHERE id=:id");
        $stmt->bindValue(':sd', $startDate, SQLITE3_TEXT);
        $stmt->bindValue(':ed', $endDate, SQLITE3_TEXT);
        $stmt->bindValue(':no', $notes, SQLITE3_TEXT);
        $stmt->bindValue(':by', $adminId, SQLITE3_INTEGER);
        $stmt->bindValue(':id', $rewardId, SQLITE3_INTEGER);
        $stmt->execute();
        $r = $this->reward($rewardId);
        $this->audit('admin:' . $adminId, 'reward_approved', $r['telegram_id'] ?? null, $rewardId,
            trim(($startDate ?: '') . ' -> ' . ($endDate ?: '')));
        return $r;
    }
    public function rejectReward($rewardId, $adminId, $reason = null) {
        $stmt = $this->db->prepare("UPDATE referral_rewards
            SET status='rejected', notes=:no, decided_by=:by, decided_at=CURRENT_TIMESTAMP WHERE id=:id");
        $stmt->bindValue(':no', $reason, SQLITE3_TEXT);
        $stmt->bindValue(':by', $adminId, SQLITE3_INTEGER);
        $stmt->bindValue(':id', $rewardId, SQLITE3_INTEGER);
        $stmt->execute();
        $r = $this->reward($rewardId);
        $this->audit('admin:' . $adminId, 'reward_rejected', $r['telegram_id'] ?? null, $rewardId, (string) $reason);
        return $r;
    }
    // Manually grant a reward to a user WITHOUT them inviting anyone.
    public function grantReward($telegramId, $tierId, $adminId, $startDate = null, $endDate = null, $notes = null) {
        $t = $this->tier($tierId);
        if (!$t) return null;
        $stmt = $this->db->prepare("INSERT INTO referral_rewards
            (telegram_id, tier_id, tier_invites, title, body, status, source, start_date, end_date, notes, decided_by, decided_at)
            VALUES (:id,:t,:inv,:ti,:bo,'approved','manual',:sd,:ed,:no,:by,CURRENT_TIMESTAMP)");
        $stmt->bindValue(':id', $telegramId, SQLITE3_INTEGER);
        $stmt->bindValue(':t', $tierId, SQLITE3_INTEGER);
        $stmt->bindValue(':inv', (int) $t['invites_required'], SQLITE3_INTEGER);
        $stmt->bindValue(':ti', $t['title'], SQLITE3_TEXT);
        $stmt->bindValue(':bo', $t['body'], SQLITE3_TEXT);
        $stmt->bindValue(':sd', $startDate, SQLITE3_TEXT);
        $stmt->bindValue(':ed', $endDate, SQLITE3_TEXT);
        $stmt->bindValue(':no', $notes, SQLITE3_TEXT);
        $stmt->bindValue(':by', $adminId, SQLITE3_INTEGER);
        $stmt->execute();
        $rid = $this->db->lastInsertRowID();
        $this->audit('admin:' . $adminId, 'reward_granted', $telegramId, $rid, $t['title'] . ' (manual)');
        return $this->reward($rid);
    }
    // User chooses when a reward starts (from the bot). Records preference; stays
    // 'earned' until an admin approves it (mirrors the mockup's approval step).
    public function setRewardStartPreference($rewardId, $mode, $startDate = null) {
        $note = 'user_start:' . $mode . ($startDate ? ' ' . $startDate : '');
        $stmt = $this->db->prepare("UPDATE referral_rewards SET start_date=COALESCE(:sd,start_date),
            notes=CASE WHEN notes IS NULL OR notes='' THEN :no ELSE notes||' | '||:no END WHERE id=:id");
        $stmt->bindValue(':sd', $startDate, SQLITE3_TEXT);
        $stmt->bindValue(':no', $note, SQLITE3_TEXT);
        $stmt->bindValue(':id', $rewardId, SQLITE3_INTEGER);
        $stmt->execute();
        $r = $this->reward($rewardId);
        $this->audit('user:' . ($r['telegram_id'] ?? ''), 'reward_start_pref', $r['telegram_id'] ?? null, $rewardId, $note);
        return $r;
    }

    // ---- Tier management (admin) ----
    public function addTier($invites, $title, $body) {
        $stmt = $this->db->prepare("INSERT INTO referral_tiers (invites_required,title,body,sort,active)
            VALUES (:inv,:ti,:bo,0,1)");
        $stmt->bindValue(':inv', (int) $invites, SQLITE3_INTEGER);
        $stmt->bindValue(':ti', $title, SQLITE3_TEXT);
        $stmt->bindValue(':bo', $body, SQLITE3_TEXT);
        $stmt->execute();
        $id = $this->db->lastInsertRowID();
        $this->audit('admin', 'tier_added', null, null, "#$id $title @ $invites");
        return $id;
    }
    public function updateTier($id, $invites, $title, $body, $active) {
        $stmt = $this->db->prepare("UPDATE referral_tiers SET invites_required=:inv,title=:ti,body=:bo,active=:ac WHERE id=:id");
        $stmt->bindValue(':inv', (int) $invites, SQLITE3_INTEGER);
        $stmt->bindValue(':ti', $title, SQLITE3_TEXT);
        $stmt->bindValue(':bo', $body, SQLITE3_TEXT);
        $stmt->bindValue(':ac', $active ? 1 : 0, SQLITE3_INTEGER);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        $this->audit('admin', 'tier_updated', null, null, "#$id $title @ $invites active=" . ($active ? 1 : 0));
    }
    public function deleteTier($id) {
        $stmt = $this->db->prepare("DELETE FROM referral_tiers WHERE id=:id");
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        $this->audit('admin', 'tier_deleted', null, null, "#$id");
    }

    // ---------------------------------------------------------------------
    // Admin listing helpers
    // ---------------------------------------------------------------------
    public function listReferrals($limit = 200) {
        $res = $this->db->query("SELECT r.*, u.name AS referrer_name FROM referrals r
            LEFT JOIN users u ON u.telegram_id = r.referrer_id
            ORDER BY r.created_at DESC LIMIT " . (int) $limit);
        $out = [];
        while ($res && ($x = $res->fetchArray(SQLITE3_ASSOC))) { $out[] = $x; }
        return $out;
    }
    public function listRewards($status = null, $limit = 200) {
        $sql = "SELECT rw.*, u.name AS user_name FROM referral_rewards rw
                LEFT JOIN users u ON u.telegram_id = rw.telegram_id";
        if ($status) $sql .= " WHERE rw.status = :st";
        $sql .= " ORDER BY rw.created_at DESC LIMIT " . (int) $limit;
        $stmt = $this->db->prepare($sql);
        if ($status) $stmt->bindValue(':st', $status, SQLITE3_TEXT);
        $res = $stmt->execute();
        $out = [];
        while ($res && ($x = $res->fetchArray(SQLITE3_ASSOC))) { $out[] = $x; }
        return $out;
    }
    public function listAudit($limit = 100) {
        $res = $this->db->query("SELECT * FROM referral_audit ORDER BY id DESC LIMIT " . (int) $limit);
        $out = [];
        while ($res && ($x = $res->fetchArray(SQLITE3_ASSOC))) { $out[] = $x; }
        return $out;
    }
    public function setReferralStatus($refId, $status, $adminId) {
        $qualifiedAt = $status === 'qualified' ? gmdate('Y-m-d H:i:s') : null;
        $stmt = $this->db->prepare("UPDATE referrals SET status=:s, flagged=CASE WHEN :s='qualified' THEN 0 ELSE flagged END,
            qualified_at=COALESCE(:qa,qualified_at) WHERE id=:id");
        $stmt->bindValue(':s', $status, SQLITE3_TEXT);
        $stmt->bindValue(':qa', $qualifiedAt, SQLITE3_TEXT);
        $stmt->bindValue(':id', $refId, SQLITE3_INTEGER);
        $stmt->execute();
        $this->audit('admin:' . $adminId, 'referral_' . $status, null, $refId, '');
    }

    public function stats() {
        return [
            'referrals'        => (int) $this->db->querySingle("SELECT COUNT(*) FROM referrals"),
            'qualified'        => (int) $this->db->querySingle("SELECT COUNT(*) FROM referrals WHERE status='qualified'"),
            'flagged'          => (int) $this->db->querySingle("SELECT COUNT(*) FROM referrals WHERE flagged=1"),
            'rewards_pending'  => (int) $this->db->querySingle("SELECT COUNT(*) FROM referral_rewards WHERE status='earned'"),
            'rewards_approved' => (int) $this->db->querySingle("SELECT COUNT(*) FROM referral_rewards WHERE status='approved'"),
        ];
    }

    // Global sweep (for the poll loop): settle any referrals whose window has
    // passed, then create rewards for any inviter who just crossed a milestone.
    // Returns [ ['telegram_id'=>id, 'reward'=>row], ... ] so callers can notify.
    public function sweepNewRewards() {
        if (!$this->isEnabled()) return [];
        $this->refreshQualifications();
        $res = $this->db->query("SELECT DISTINCT referrer_id FROM referrals WHERE status='qualified'");
        $ids = [];
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $ids[] = (int) $r['referrer_id']; }
        $out = [];
        foreach ($ids as $uid) {
            foreach ($this->checkMilestones($uid) as $rw) { $out[] = ['telegram_id' => $uid, 'reward' => $rw]; }
        }
        return $out;
    }

    public function audit($actor, $event, $telegramId, $refId, $detail = '') {
        try {
            $stmt = $this->db->prepare("INSERT INTO referral_audit (actor,event,telegram_id,ref_id,detail)
                VALUES (:a,:e,:t,:r,:d)");
            $stmt->bindValue(':a', (string) $actor, SQLITE3_TEXT);
            $stmt->bindValue(':e', (string) $event, SQLITE3_TEXT);
            $stmt->bindValue(':t', $telegramId, $telegramId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
            $stmt->bindValue(':r', $refId, $refId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
            $stmt->bindValue(':d', (string) $detail, SQLITE3_TEXT);
            $stmt->execute();
        } catch (\Throwable $e) { /* audit must never break the flow */ }
    }
}

// =====================================================================
// Bot UI (Invite & Earn screens). Use globals $tg (Telegram) and
// $referral (HL_Referral), set up in webhook.php. These mirror the
// client's mockup screen-by-screen.
// =====================================================================

// Small helper: progress bar like [#####-----] for a tier.
function inv_bar($have, $need) {
    $need = max(1, (int) $need);
    $filled = (int) round(min(1, $have / $need) * 10);
    return str_repeat("\xE2\x96\xA0", $filled) . str_repeat("\xE2\x96\xA1", 10 - $filled);
}

function inviteGuard($userId) {
    global $tg, $db, $referral;
    if (!$db->getUser($userId)) { startRegistration($userId, 'invite'); return false; }
    if (!$referral->isEnabled()) {
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x8E\x81 <b>Invite & Earn</b>\n\nThis feature is currently turned off. Please check back soon!",
            [[['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);
        return false;
    }
    return true;
}

function inviteButtons() {
    return [
        [
            ['text' => "\xF0\x9F\x94\x97 My Referral Link", 'callback_data' => 'inv_link'],
            ['text' => "\xF0\x9F\x93\x88 My Progress",      'callback_data' => 'inv_progress'],
        ],
        [
            ['text' => "\xF0\x9F\x8E\x81 Rewards",          'callback_data' => 'inv_rewards'],
            ['text' => "\xF0\x9F\x8F\xA0 Main Menu",        'callback_data' => 'main_menu'],
        ],
    ];
}

// Screen 2 - Welcome / How it works.
function inviteEarnHome($userId) {
    global $tg, $referral;
    if (!inviteGuard($userId)) return;
    $referral->ensureCode($userId);
    // Proactively surface any freshly-earned milestone as a claim prompt.
    $newly = $referral->checkMilestones($userId);

    $usaOnly = (string) $referral->setting('referral_usa_only', '0') === '1';
    $usaLine = $usaOnly ? "\xF0\x9F\x87\xBA\xF0\x9F\x87\xB8 Available to users in the USA.\n\n" : '';
    $groupName = trim((string) $referral->setting('group_name', 'HabeshaList'));
    if ($groupName === '') $groupName = 'HabeshaList';
    $gn = htmlspecialchars($groupName);
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x8E\x81 <b>Welcome to Invite &amp; Earn!</b>\n\n" .
        $usaLine .
        "Invite your friends to {$gn} and earn incredible rewards that help your business grow.\n\n" .
        "<b>How Invite &amp; Earn Works</b>\n" .
        "1\xEF\xB8\x8F\xE2\x83\xA3 Invite friends using your referral link.\n" .
        "2\xEF\xB8\x8F\xE2\x83\xA3 They join the {$gn} Telegram group.\n" .
        "3\xEF\xB8\x8F\xE2\x83\xA3 They register and stay active.\n" .
        "4\xEF\xB8\x8F\xE2\x83\xA3 Admin verifies and approves the invites.\n" .
        "5\xEF\xB8\x8F\xE2\x83\xA3 You reach milestones and claim your rewards.",
        inviteButtons());

    foreach ($newly as $rw) { inviteRewardUnlocked($userId, $rw); }
}

// Screen 3 - My Referral Link.
function inviteShowLink($userId) {
    global $tg, $referral;
    if (!inviteGuard($userId)) return;
    $code = $referral->ensureCode($userId);
    $botUser = getBotUsername();
    $link = $referral->link($code, $botUser);
    $shareText = rawurlencode("Join me on HabeshaList - the Habesha community marketplace! Use my link:");
    $shareUrl = "https://t.me/share/url?url=" . rawurlencode($link) . "&text=" . $shareText;

    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x94\x97 <b>Your Personal Referral Link</b>\n\n" .
        "<code>" . htmlspecialchars($link) . "</code>\n\n" .
        "Your code: <b>{$code}</b>\n\n" .
        "Share this link with your friends. When they join and register through it, your invite is counted.",
        [
            [['text' => "\xF0\x9F\x93\xA4 Share Link", 'url' => $shareUrl]],
            [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'invite']],
        ]);
}

// Screen 4 - My Progress.
function inviteShowProgress($userId) {
    global $tg, $referral;
    if (!inviteGuard($userId)) return;
    $referral->checkMilestones($userId);
    $p = $referral->progress($userId);

    $next = $p['next'];
    $ringTarget = $next ? (int) $next['invites_required'] : ($p['tiers'] ? (int) end($p['tiers'])['invites_required'] : 0);
    $ring = $ringTarget > 0 ? "{$p['qualified']} / {$ringTarget}" : (string) $p['qualified'];

    $msg  = "\xF0\x9F\x93\x88 <b>Your Progress</b>\n\n";
    $msg .= "\xE2\xAD\x90 <b>{$ring}</b> qualified invites\n";
    if ($next) {
        $msg .= "\xF0\x9F\x8E\xAF {$p['needed']} more invite" . ($p['needed'] === 1 ? '' : 's') . " until your next reward: <b>" . htmlspecialchars($next['title']) . "</b>\n";
    } else {
        $msg .= "\xF0\x9F\x8F\x86 You've reached the top reward tier. Amazing!\n";
    }
    $earned = 0;
    foreach ($p['rewards'] as $rw) { if (in_array($rw['status'], ['earned','approved'], true)) $earned++; }
    $msg .= "\n<b>Summary</b>\n";
    $msg .= "\xE2\x80\xA2 Total invites joined: <b>{$p['joined']}</b>\n";
    $msg .= "\xE2\x80\xA2 Successful (qualified) referrals: <b>{$p['qualified']}</b>\n";
    $msg .= "\xE2\x80\xA2 Rewards earned: <b>{$earned}</b>\n\n";

    $msg .= "<b>Reward Milestones</b>\n";
    foreach ($p['tiers'] as $t) {
        $need = (int) $t['invites_required'];
        $done = $p['qualified'] >= $need;
        $mark = $done ? "\xE2\x9C\x85" : "\xE2\xAC\x9C";
        $msg .= "{$mark} " . htmlspecialchars($t['title']) . " - " . min($p['qualified'], $need) . "/{$need}\n";
        $msg .= "   " . inv_bar($p['qualified'], $need) . "\n";
    }

    // Referral history (recent).
    $hist = $referral->history($userId, 8);
    if ($hist) {
        $msg .= "\n<b>Referral History</b>\n";
        foreach ($hist as $h) {
            $nm = $h['referred_name'] ? htmlspecialchars($h['referred_name']) : 'A friend';
            $st = $h['flagged'] ? 'under review' : ($h['status'] === 'qualified' ? 'qualified' : 'pending');
            $msg .= "\xE2\x80\xA2 {$nm} - {$st}\n";
        }
    }

    $tg->sendInlineButtons($userId, $msg, [
        [
            ['text' => "\xF0\x9F\x94\x97 My Referral Link", 'callback_data' => 'inv_link'],
            ['text' => "\xF0\x9F\x8E\x81 Rewards",          'callback_data' => 'inv_rewards'],
        ],
        [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'invite']],
    ]);
}

// Screen 7 - Rewards you can earn (+ claim buttons for earned rewards).
function inviteShowRewards($userId) {
    global $tg, $referral;
    if (!inviteGuard($userId)) return;
    $referral->checkMilestones($userId);
    $qualified = $referral->countQualified($userId);

    $msg = "\xF0\x9F\x8E\x81 <b>Rewards You Can Earn</b>\n\n";
    foreach ($referral->tiers(true) as $t) {
        $need = (int) $t['invites_required'];
        $done = $qualified >= $need;
        $mark = $done ? "\xE2\x9C\x85" : "\xF0\x9F\x94\x92";
        $msg .= "{$mark} <b>{$need} Invites - " . htmlspecialchars($t['title']) . "</b>\n";
        foreach (explode("\n", (string) $t['body']) as $ln) {
            if (trim($ln) !== '') $msg .= "   \xE2\x80\xA2 " . htmlspecialchars(trim($ln)) . "\n";
        }
        $msg .= "\n";
    }

    // Buttons: claim any earned-but-unclaimed reward.
    $rows = [];
    foreach ($referral->rewardsFor($userId) as $rw) {
        if ($rw['status'] === 'earned') {
            $rows[] = [['text' => "\xF0\x9F\x8E\x89 Claim: " . mb_strimwidth($rw['title'], 0, 28, '...'),
                       'callback_data' => 'inv_claim_' . $rw['id']]];
        }
    }
    $rows[] = [['text' => "\xF0\x9F\x93\x8B View My Rewards", 'callback_data' => 'inv_myrewards']];
    $rows[] = [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'invite']];
    $tg->sendInlineButtons($userId, $msg, $rows);
}

// A freshly-unlocked reward (pushed proactively or from Rewards screen).
function inviteRewardUnlocked($userId, $rw) {
    global $tg;
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x8E\x89 <b>Milestone Reached!</b>\n\n" .
        "You reached {$rw['tier_invites']} invites!\n\n" .
        "\xF0\x9F\x8E\x81 Reward Unlocked: <b>" . htmlspecialchars($rw['title']) . "</b>\n\n" .
        "Would you like to use this reward now or choose a start date?",
        [
            [['text' => "\xE2\x96\xB6\xEF\xB8\x8F Start Now",       'callback_data' => 'inv_start_' . $rw['id']]],
            [['text' => "\xF0\x9F\x93\x85 Choose Start Date", 'callback_data' => 'inv_date_' . $rw['id']]],
            [['text' => "\xF0\x9F\x92\xBE Save for Later",     'callback_data' => 'inv_save_' . $rw['id']]],
            [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'inv_rewards']],
        ]);
}

// Screen 9 - claim a specific reward.
function inviteClaimReward($userId, $rewardId) {
    global $tg, $referral;
    if (!inviteGuard($userId)) return;
    $rw = $referral->reward($rewardId);
    if (!$rw || (int) $rw['telegram_id'] !== (int) $userId) {
        $tg->sendMessage($userId, "Sorry, that reward could not be found.");
        return;
    }
    inviteRewardUnlocked($userId, $rw);
}

// Start Now / Save for Later.
function inviteRewardStartMode($userId, $rewardId, $mode) {
    global $tg, $referral;
    if (!inviteGuard($userId)) return;
    $rw = $referral->reward($rewardId);
    if (!$rw || (int) $rw['telegram_id'] !== (int) $userId) { $tg->sendMessage($userId, "Reward not found."); return; }

    if ($mode === 'start') {
        $today = (new DateTime('now'))->format('Y-m-d');
        $referral->setRewardStartPreference($rewardId, 'start_now', $today);
        $tg->sendInlineButtons($userId,
            "\xE2\x9C\x85 <b>Great!</b> We've noted you'd like to start <b>" . htmlspecialchars($rw['title']) . "</b> right away.\n\n" .
            "Our team will review and approve it shortly - you'll get a message once it's active.",
            [[['text' => "\xF0\x9F\x93\x8B View My Rewards", 'callback_data' => 'inv_myrewards']],
             [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);
    } else { // save
        $referral->setRewardStartPreference($rewardId, 'save_later', null);
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x92\xBE Saved! You can start <b>" . htmlspecialchars($rw['title']) . "</b> whenever you're ready from View My Rewards.",
            [[['text' => "\xF0\x9F\x93\x8B View My Rewards", 'callback_data' => 'inv_myrewards']],
             [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);
    }
}

// Choose Start Date - ask the user to type a date.
function inviteRewardChooseDate($userId, $rewardId) {
    global $tg, $db, $referral;
    if (!inviteGuard($userId)) return;
    $rw = $referral->reward($rewardId);
    if (!$rw || (int) $rw['telegram_id'] !== (int) $userId) { $tg->sendMessage($userId, "Reward not found."); return; }
    $db->setState($userId, 'inv_reward_date', ['reward_id' => (int) $rewardId]);
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x93\x85 When would you like <b>" . htmlspecialchars($rw['title']) . "</b> to start?\n\n" .
        "Please type a date, e.g. <b>2026-08-01</b> or <b>Aug 1 2026</b>.",
        [[['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'inv_claim_' . $rewardId]]]);
}

// Handle the typed start date (called from the text state handler).
function inviteRewardSaveDate($userId, $text, $state) {
    global $tg, $db, $referral;
    $rewardId = (int) ($state['data']['reward_id'] ?? 0);
    $ts = strtotime($text);
    if (!$ts) {
        $tg->sendMessage($userId, "Sorry, I couldn't read that date. Please type it like <b>2026-08-01</b>:");
        return;
    }
    $date = date('Y-m-d', $ts);
    $db->setState($userId, 'idle', []);
    $rw = $referral->setRewardStartPreference($rewardId, 'choose_date', $date);
    $tg->sendInlineButtons($userId,
        "\xE2\x9C\x85 Noted! You'd like <b>" . htmlspecialchars($rw['title'] ?? 'your reward') . "</b> to start on <b>{$date}</b>.\n\n" .
        "Our team will review and approve it - you'll be notified once it's active.",
        [[['text' => "\xF0\x9F\x93\x8B View My Rewards", 'callback_data' => 'inv_myrewards']],
         [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);
}

// Screen 10 - View My Rewards with status.
function inviteViewMyRewards($userId) {
    global $tg, $referral;
    if (!inviteGuard($userId)) return;
    $rewards = $referral->rewardsFor($userId);
    if (!$rewards) {
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x93\x8B <b>My Rewards</b>\n\nYou haven't earned any rewards yet. Keep inviting friends!",
            [[['text' => "\xF0\x9F\x94\x97 My Referral Link", 'callback_data' => 'inv_link']],
             [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'invite']]]);
        return;
    }
    $labels = ['earned' => "\xE2\x8F\xB3 Pending approval", 'approved' => "\xE2\x9C\x85 Approved", 'rejected' => "\xE2\x9D\x8C Not approved"];
    $msg = "\xF0\x9F\x93\x8B <b>My Rewards</b>\n\n";
    foreach ($rewards as $rw) {
        $st = $labels[$rw['status']] ?? $rw['status'];
        $msg .= "\xF0\x9F\x8E\x81 <b>" . htmlspecialchars($rw['title']) . "</b>\n";
        $msg .= "   Status: {$st}\n";
        if ($rw['start_date']) $msg .= "   Start: " . htmlspecialchars($rw['start_date']) . ($rw['end_date'] ? " \xE2\x86\x92 " . htmlspecialchars($rw['end_date']) : '') . "\n";
        $msg .= "\n";
    }
    $tg->sendInlineButtons($userId, $msg,
        [[['text' => "\xF0\x9F\x8E\x81 Rewards", 'callback_data' => 'inv_rewards']],
         [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']]]);
}
