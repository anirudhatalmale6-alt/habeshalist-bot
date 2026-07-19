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
    // 4th element = the promotion package the reward grants when redeemed, so the
    // reward is delivered exactly like a paid promotion (blank = the team sets it
    // up manually, e.g. a multi-part bundle that isn't a single package).
    const DEFAULT_TIERS = [
        [20,  '1 Month Telegram Promotion',            "2 promotional posts per week for 1 month.", 'monthly'],
        [50,  '1 Week Premium Business of the Week',   "Featured and pinned in the group for 7 days.", 'botw'],
        [100, 'Business Growth Package',               "3 Months Telegram Promotion\n1 Month Business of the Week\nHomepage Featured Listing\nOne Promotional Video/Reel", ''],
    ];

    const DEFAULTS = [
        'invite_earn_enabled'       => '1',   // feature master switch
        'referral_qualify_days'     => '7',   // days a referral must settle before it counts
        'referral_usa_only'         => '0',   // informational gate shown in the bot copy
        'referral_require_group_join' => '1', // referral only counts once the friend joins the group
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
            group_joined INTEGER NOT NULL DEFAULT 0,
            group_joined_at DATETIME,
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
            fulfill_package TEXT,
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
            status TEXT NOT NULL DEFAULT 'earned',   -- earned | redeemed  (approved|rejected kept for legacy rows)
            source TEXT NOT NULL DEFAULT 'auto',      -- auto | manual
            fulfill_package TEXT,                     -- promo package this reward grants when redeemed
            promo_id INTEGER,                         -- the promotion created when the user redeemed it
            start_date TEXT,
            end_date TEXT,
            notes TEXT,
            decided_by INTEGER,
            decided_at DATETIME,
            redeemed_at DATETIME,
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
        // referrals.group_joined (added for the "must join the group" rule; tables
        // created before this feature won't have it).
        $rcols = [];
        $rr = $this->db->query("PRAGMA table_info(referrals)");
        while ($rr && ($r = $rr->fetchArray(SQLITE3_ASSOC))) { $rcols[] = $r['name']; }
        if ($rcols && !in_array('group_joined', $rcols, true)) {
            $this->db->exec("ALTER TABLE referrals ADD COLUMN group_joined INTEGER NOT NULL DEFAULT 0");
        }
        if ($rcols && !in_array('group_joined_at', $rcols, true)) {
            $this->db->exec("ALTER TABLE referrals ADD COLUMN group_joined_at DATETIME");
        }
        // referral_tiers.fulfill_package (added for reward auto-fulfillment; tiers
        // created before this feature won't have it).
        $tcols = [];
        $tr = $this->db->query("PRAGMA table_info(referral_tiers)");
        while ($tr && ($r = $tr->fetchArray(SQLITE3_ASSOC))) { $tcols[] = $r['name']; }
        if ($tcols && !in_array('fulfill_package', $tcols, true)) {
            $this->db->exec("ALTER TABLE referral_tiers ADD COLUMN fulfill_package TEXT");
        }
        // referral_rewards new columns (redemption -> promotion tracking).
        $wcols = [];
        $wr = $this->db->query("PRAGMA table_info(referral_rewards)");
        while ($wr && ($r = $wr->fetchArray(SQLITE3_ASSOC))) { $wcols[] = $r['name']; }
        if ($wcols && !in_array('fulfill_package', $wcols, true)) {
            $this->db->exec("ALTER TABLE referral_rewards ADD COLUMN fulfill_package TEXT");
        }
        if ($wcols && !in_array('promo_id', $wcols, true)) {
            $this->db->exec("ALTER TABLE referral_rewards ADD COLUMN promo_id INTEGER");
        }
        if ($wcols && !in_array('redeemed_at', $wcols, true)) {
            $this->db->exec("ALTER TABLE referral_rewards ADD COLUMN redeemed_at DATETIME");
        }
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
                $stmt = $this->db->prepare("INSERT INTO referral_tiers (invites_required,title,body,fulfill_package,sort,active)
                                            VALUES (:inv,:ti,:bo,:pk,:so,1)");
                $stmt->bindValue(':inv', (int) $t[0], SQLITE3_INTEGER);
                $stmt->bindValue(':ti', $t[1], SQLITE3_TEXT);
                $stmt->bindValue(':bo', $t[2], SQLITE3_TEXT);
                $stmt->bindValue(':pk', $t[3] ?? '', SQLITE3_TEXT);
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

    // Is the "must join the group" rule active AND a group actually configured?
    public function requiresGroupJoin() {
        if ((string) $this->setting('referral_require_group_join', self::DEFAULTS['referral_require_group_join']) !== '1') return false;
        return trim((string) $this->setting('sched_group_chat_id', '')) !== '';
    }

    // Promote settled referrals registered -> qualified. Safe to call often.
    // When the group-join rule is on, a referral must also be marked group_joined.
    public function refreshQualifications() {
        $now = gmdate('Y-m-d H:i:s');
        $joinGate = $this->requiresGroupJoin() ? " AND group_joined=1" : "";
        $stmt = $this->db->prepare("UPDATE referrals SET status='qualified', qualified_at=:now
            WHERE status='registered' AND flagged=0 AND qualifies_at IS NOT NULL AND qualifies_at <= :now" . $joinGate);
        $stmt->bindValue(':now', $now, SQLITE3_TEXT);
        $stmt->execute();
        return $this->db->changes();
    }

    // The referral row where this user is the invited person (or null).
    public function referralByReferred($referredId) {
        $stmt = $this->db->prepare("SELECT * FROM referrals WHERE referred_id = :r");
        $stmt->bindValue(':r', $referredId, SQLITE3_INTEGER);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
    }

    // Record that an invited user has joined the group. Idempotent. Returns the
    // (updated) referral row when this call newly set the flag, else null - so
    // the caller only notifies the inviter once.
    public function markGroupJoined($referredId) {
        $row = $this->referralByReferred($referredId);
        if (!$row) return null;
        if ((int) $row['group_joined'] === 1) return null;
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->db->prepare("UPDATE referrals SET group_joined=1, group_joined_at=:now WHERE id=:id");
        $stmt->bindValue(':now', $now, SQLITE3_TEXT);
        $stmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
        $stmt->execute();
        $this->audit('user:' . $referredId, 'group_joined', $row['referrer_id'], $row['id'], 'referred=' . $referredId);
        $row['group_joined'] = 1; $row['group_joined_at'] = $now;
        return $row;
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
    public function countQualified($telegramId) {   // lifetime successful invites
        $stmt = $this->db->prepare("SELECT COUNT(*) c FROM referrals
            WHERE referrer_id = :id AND status = 'qualified'");
        $stmt->bindValue(':id', $telegramId, SQLITE3_INTEGER);
        return (int) ($stmt->execute()->fetchArray(SQLITE3_ASSOC)['c'] ?? 0);
    }
    // How many qualified invites this user has already "spent" earning rewards.
    // Each reward the user has earned consumes its tier's required invites, so
    // progress toward the NEXT reward resets after every claim. Legacy rejected
    // rewards don't consume anything.
    public function spentInvites($telegramId) {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(tier_invites),0) s FROM referral_rewards
            WHERE telegram_id = :id AND status <> 'rejected'");
        $stmt->bindValue(':id', $telegramId, SQLITE3_INTEGER);
        return (int) ($stmt->execute()->fetchArray(SQLITE3_ASSOC)['s'] ?? 0);
    }
    // Qualified invites still available toward the next reward (lifetime minus spent).
    public function availableInvites($telegramId) {
        return max(0, $this->countQualified($telegramId) - $this->spentInvites($telegramId));
    }
    // How many rewards this user has earned so far (drives which tier is next).
    public function rewardCount($telegramId) {
        $stmt = $this->db->prepare("SELECT COUNT(*) c FROM referral_rewards
            WHERE telegram_id = :id AND status <> 'rejected'");
        $stmt->bindValue(':id', $telegramId, SQLITE3_INTEGER);
        return (int) ($stmt->execute()->fetchArray(SQLITE3_ASSOC)['c'] ?? 0);
    }
    // The tier the user is currently working toward: the next rung on the ladder,
    // or the top tier once every rung has been claimed (so inviting keeps paying).
    public function nextTier($telegramId) {
        $tiers = $this->tiers(true);
        if (!$tiers) return null;
        $idx = min($this->rewardCount($telegramId), count($tiers) - 1);
        return $tiers[$idx];
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
    // Referrals still waiting on the group-join step before they can count.
    public function countAwaitingGroupJoin($telegramId) {
        if (!$this->requiresGroupJoin()) return 0;
        $stmt = $this->db->prepare("SELECT COUNT(*) c FROM referrals
            WHERE referrer_id = :id AND status = 'registered' AND flagged = 0 AND group_joined = 0");
        $stmt->bindValue(':id', $telegramId, SQLITE3_INTEGER);
        return (int) ($stmt->execute()->fetchArray(SQLITE3_ASSOC)['c'] ?? 0);
    }
    // Full progress snapshot for the bot's "My Progress" screen.
    // 'qualified' is the LIFETIME successful-invite count (Total Invites - never
    // resets). 'available' is what counts toward the next reward and RESETS every
    // time a reward is claimed. 'progress' is available capped at the next tier.
    public function progress($telegramId) {
        // Settle + auto-earn any rewards first, so 'available'/'next' reflect the
        // rewards already handed out.
        $this->checkMilestones($telegramId);
        $joined    = $this->countJoined($telegramId);
        $qualified = $this->countQualified($telegramId);
        $spent     = $this->spentInvites($telegramId);
        $available = max(0, $qualified - $spent);
        $tiers     = $this->tiers(true);
        $next      = $this->nextTier($telegramId);
        $need      = $next ? (int) $next['invites_required'] : 0;
        $needed    = $next ? max(0, $need - $available) : 0;
        return [
            'joined'         => $joined,
            'qualified'      => $qualified,   // lifetime successful (Total Invites)
            'spent'          => $spent,
            'available'      => $available,   // toward next reward (resets on claim)
            'progress'       => $next ? min($available, $need) : $available,
            'awaiting_group' => $this->countAwaitingGroupJoin($telegramId),
            'tiers'          => $tiers,
            'next'           => $next,
            'needed'         => $needed,
            'rewards'        => $this->rewardsFor($telegramId),
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
    // Rewards this user has earned but not yet redeemed.
    public function earnedRewards($telegramId) {
        $stmt = $this->db->prepare("SELECT * FROM referral_rewards
            WHERE telegram_id = :id AND status = 'earned' ORDER BY created_at ASC");
        $stmt->bindValue(':id', $telegramId, SQLITE3_INTEGER);
        $res = $stmt->execute();
        $out = [];
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $out[] = $r; }
        return $out;
    }
    // The promo package a reward grants when redeemed (blank = manual fulfilment).
    public function rewardPackage($reward) {
        if (!empty($reward['fulfill_package'])) return (string) $reward['fulfill_package'];
        // Fall back to the tier's configured package (older reward rows).
        if (!empty($reward['tier_id'])) {
            $t = $this->tier((int) $reward['tier_id']);
            if ($t && !empty($t['fulfill_package'])) return (string) $t['fulfill_package'];
        }
        return '';
    }
    // Mark an earned reward as redeemed. Idempotent-ish: returns false if it was
    // not in the 'earned' state (already redeemed / not found), so a double tap
    // can never create two promotions from one reward.
    public function markRewardRedeemed($rewardId, $promoId = null) {
        $rw = $this->reward($rewardId);
        if (!$rw || $rw['status'] !== 'earned') return false;
        $stmt = $this->db->prepare("UPDATE referral_rewards
            SET status='redeemed', promo_id=:pid, redeemed_at=CURRENT_TIMESTAMP
            WHERE id=:id AND status='earned'");
        $stmt->bindValue(':pid', $promoId, $promoId === null ? SQLITE3_NULL : SQLITE3_INTEGER);
        $stmt->bindValue(':id', $rewardId, SQLITE3_INTEGER);
        $stmt->execute();
        if ($this->db->changes() < 1) return false;
        $this->audit('user:' . $rw['telegram_id'], 'reward_redeemed', $rw['telegram_id'], $rewardId,
            $rw['title'] . ($promoId ? ' -> promo ' . $promoId : ' (manual)'));
        return true;
    }
    // Automatically earn any reward the user has now qualified for. Consumption
    // model: each reward the user climbs to spends that tier's required invites,
    // so progress resets and they must invite the required number of NEW eligible
    // friends again for the following reward. Rewards are earned WITHOUT any admin
    // approval - the group-join + settle rules already verify each invite. Returns
    // the list of newly-earned reward rows (for notifying the user).
    public function checkMilestones($telegramId) {
        $this->refreshQualifications();
        $tiers = $this->tiers(true);
        if (!$tiers) return [];
        $newly = [];
        // Award one reward per pass; the loop keeps going while enough available
        // invites remain for the next rung (a guard caps runaway loops).
        for ($guard = 0; $guard < 100; $guard++) {
            $available = $this->availableInvites($telegramId);
            $next = $this->nextTier($telegramId);
            if (!$next) break;
            $need = (int) $next['invites_required'];
            if ($need <= 0 || $available < $need) break;
            $stmt = $this->db->prepare("INSERT INTO referral_rewards
                (telegram_id, tier_id, tier_invites, title, body, status, source, fulfill_package)
                VALUES (:id,:t,:inv,:ti,:bo,'earned','auto',:pk)");
            $stmt->bindValue(':id', $telegramId, SQLITE3_INTEGER);
            $stmt->bindValue(':t', $next['id'], SQLITE3_INTEGER);
            $stmt->bindValue(':inv', $need, SQLITE3_INTEGER);
            $stmt->bindValue(':ti', $next['title'], SQLITE3_TEXT);
            $stmt->bindValue(':bo', $next['body'], SQLITE3_TEXT);
            $stmt->bindValue(':pk', (string) ($next['fulfill_package'] ?? ''), SQLITE3_TEXT);
            $stmt->execute();
            $rid = $this->db->lastInsertRowID();
            $this->audit('system', 'reward_earned', $telegramId, $rid, $next['title'] . ' @ ' . $need);
            $newly[] = $this->reward($rid);
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
    // Manually grant a reward to a user WITHOUT them inviting anyone. It's created
    // as 'earned' so the user can redeem it (pick a date/time) exactly like an
    // auto-earned reward. Note: a granted reward consumes no invites of its own -
    // spentInvites counts it, so it also advances their reward ladder like any
    // other reward (intended: a gift counts as one of their rewards).
    public function grantReward($telegramId, $tierId, $adminId, $startDate = null, $endDate = null, $notes = null) {
        $t = $this->tier($tierId);
        if (!$t) return null;
        $stmt = $this->db->prepare("INSERT INTO referral_rewards
            (telegram_id, tier_id, tier_invites, title, body, status, source, fulfill_package, start_date, end_date, notes)
            VALUES (:id,:t,:inv,:ti,:bo,'earned','manual',:pk,:sd,:ed,:no)");
        $stmt->bindValue(':id', $telegramId, SQLITE3_INTEGER);
        $stmt->bindValue(':t', $tierId, SQLITE3_INTEGER);
        $stmt->bindValue(':inv', (int) $t['invites_required'], SQLITE3_INTEGER);
        $stmt->bindValue(':ti', $t['title'], SQLITE3_TEXT);
        $stmt->bindValue(':bo', $t['body'], SQLITE3_TEXT);
        $stmt->bindValue(':pk', (string) ($t['fulfill_package'] ?? ''), SQLITE3_TEXT);
        $stmt->bindValue(':sd', $startDate, SQLITE3_TEXT);
        $stmt->bindValue(':ed', $endDate, SQLITE3_TEXT);
        $stmt->bindValue(':no', $notes, SQLITE3_TEXT);
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
    public function addTier($invites, $title, $body, $fulfillPackage = '') {
        $stmt = $this->db->prepare("INSERT INTO referral_tiers (invites_required,title,body,fulfill_package,sort,active)
            VALUES (:inv,:ti,:bo,:pk,0,1)");
        $stmt->bindValue(':inv', (int) $invites, SQLITE3_INTEGER);
        $stmt->bindValue(':ti', $title, SQLITE3_TEXT);
        $stmt->bindValue(':bo', $body, SQLITE3_TEXT);
        $stmt->bindValue(':pk', (string) $fulfillPackage, SQLITE3_TEXT);
        $stmt->execute();
        $id = $this->db->lastInsertRowID();
        $this->audit('admin', 'tier_added', null, null, "#$id $title @ $invites");
        return $id;
    }
    public function updateTier($id, $invites, $title, $body, $active, $fulfillPackage = null) {
        if ($fulfillPackage === null) {
            $stmt = $this->db->prepare("UPDATE referral_tiers SET invites_required=:inv,title=:ti,body=:bo,active=:ac WHERE id=:id");
        } else {
            $stmt = $this->db->prepare("UPDATE referral_tiers SET invites_required=:inv,title=:ti,body=:bo,active=:ac,fulfill_package=:pk WHERE id=:id");
            $stmt->bindValue(':pk', (string) $fulfillPackage, SQLITE3_TEXT);
        }
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
            'rewards_earned'   => (int) $this->db->querySingle("SELECT COUNT(*) FROM referral_rewards WHERE status='earned'"),
            'rewards_redeemed' => (int) $this->db->querySingle("SELECT COUNT(*) FROM referral_rewards WHERE status IN ('redeemed','approved')"),
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
        "2\xEF\xB8\x8F\xE2\x83\xA3 They register and join the {$gn} Telegram group.\n" .
        "3\xEF\xB8\x8F\xE2\x83\xA3 Each verified invite is counted automatically.\n" .
        "4\xEF\xB8\x8F\xE2\x83\xA3 Reach a milestone and your reward unlocks instantly.\n" .
        "5\xEF\xB8\x8F\xE2\x83\xA3 Redeem it, pick a date &amp; time, and it's scheduled just like a paid promotion.",
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
    $p = $referral->progress($userId);

    $next = $p['next'];
    $avail = (int) $p['available'];
    $ringTarget = $next ? (int) $next['invites_required'] : 0;
    $ring = $ringTarget > 0 ? "{$avail} / {$ringTarget}" : (string) $avail;

    $msg  = "\xF0\x9F\x93\x88 <b>Your Progress</b>\n\n";
    if ($next) {
        $msg .= "\xF0\x9F\x8E\xAF <b>Progress to your next reward</b>\n";
        $msg .= "\xE2\xAD\x90 <b>{$ring}</b> invites\n";
        $msg .= "   " . inv_bar($avail, $ringTarget) . "\n";
        $msg .= "\xF0\x9F\x8E\x81 Next reward: <b>" . htmlspecialchars($next['title']) . "</b>\n";
        if ($p['needed'] > 0) {
            $msg .= "\xF0\x9F\x91\x89 Invite <b>{$p['needed']}</b> more eligible friend" . ($p['needed'] === 1 ? '' : 's') . " to unlock it.\n";
        } else {
            $msg .= "\xE2\x9C\x85 Unlocked! Tap Rewards to redeem it.\n";
        }
    } else {
        $msg .= "\xF0\x9F\x8E\x81 Rewards aren't set up yet - check back soon!\n";
    }

    $redeemed = 0; $ready = 0;
    foreach ($p['rewards'] as $rw) {
        if ($rw['status'] === 'earned') $ready++;
        elseif (in_array($rw['status'], ['redeemed','approved'], true)) $redeemed++;
    }
    $msg .= "\n<b>Summary</b>\n";
    $msg .= "\xE2\x80\xA2 Total invites (lifetime): <b>{$p['qualified']}</b>\n";
    if (!empty($p['awaiting_group'])) {
        $msg .= "\xE2\x80\xA2 Waiting to join the group: <b>{$p['awaiting_group']}</b>\n";
    }
    $msg .= "\xE2\x80\xA2 Rewards ready to redeem: <b>{$ready}</b>\n";
    $msg .= "\xE2\x80\xA2 Rewards claimed: <b>{$redeemed}</b>\n\n";

    $msg .= "<b>Reward Milestones</b> <i>(each one needs a fresh set of invites)</i>\n";
    foreach ($p['tiers'] as $t) {
        $need = (int) $t['invites_required'];
        $done = $avail >= $need;
        $mark = $done ? "\xE2\x9C\x85" : "\xE2\xAC\x9C";
        $msg .= "{$mark} " . htmlspecialchars($t['title']) . " - " . $need . " invites\n";
    }

    // Referral history (recent).
    $hist = $referral->history($userId, 8);
    if ($hist) {
        $msg .= "\n<b>Referral History</b>\n";
        $needJoin = $referral->requiresGroupJoin();
        foreach ($hist as $h) {
            $nm = $h['referred_name'] ? htmlspecialchars($h['referred_name']) : 'A friend';
            if ($h['flagged']) {
                $st = 'under review';
            } elseif ($h['status'] === 'qualified') {
                $st = 'qualified';
            } elseif ($needJoin && (int) ($h['group_joined'] ?? 0) === 0) {
                $st = 'needs to join the group';
            } else {
                $st = 'pending';
            }
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
    $available = $referral->availableInvites($userId);

    $msg = "\xF0\x9F\x8E\x81 <b>Rewards You Can Earn</b>\n\n";
    foreach ($referral->tiers(true) as $t) {
        $need = (int) $t['invites_required'];
        $done = $available >= $need;
        $mark = $done ? "\xE2\x9C\x85" : "\xF0\x9F\x94\x92";
        $msg .= "{$mark} <b>{$need} Invites - " . htmlspecialchars($t['title']) . "</b>\n";
        foreach (explode("\n", (string) $t['body']) as $ln) {
            if (trim($ln) !== '') $msg .= "   \xE2\x80\xA2 " . htmlspecialchars(trim($ln)) . "\n";
        }
        $msg .= "\n";
    }
    $msg .= "<i>Each reward uses up the invites it costs - keep inviting to unlock the next one.</i>";

    // Buttons: redeem any earned-but-unredeemed reward.
    $rows = [];
    foreach ($referral->earnedRewards($userId) as $rw) {
        $rows[] = [['text' => "\xF0\x9F\x8E\x89 Redeem: " . mb_strimwidth($rw['title'], 0, 26, '...'),
                   'callback_data' => 'inv_redeem_' . $rw['id']]];
    }
    $rows[] = [['text' => "\xF0\x9F\x93\x8B View My Rewards", 'callback_data' => 'inv_myrewards']];
    $rows[] = [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'invite']];
    $tg->sendInlineButtons($userId, $msg, $rows);
}

// A freshly-unlocked reward (pushed proactively or from Rewards screen). The user
// redeems it and picks a date/time; it's then scheduled just like a paid promotion.
function inviteRewardUnlocked($userId, $rw) {
    global $tg;
    $tg->sendInlineButtons($userId,
        "\xF0\x9F\x8E\x89 <b>Milestone Reached!</b>\n\n" .
        "You earned <b>{$rw['tier_invites']}</b> qualified invites!\n\n" .
        "\xF0\x9F\x8E\x81 Reward Unlocked: <b>" . htmlspecialchars($rw['title']) . "</b>\n\n" .
        "Redeem it now to set it up and schedule your promotion.",
        [
            [['text' => "\xF0\x9F\x8E\x89 Redeem Now", 'callback_data' => 'inv_redeem_' . $rw['id']]],
            [['text' => "\xF0\x9F\x93\x8B View My Rewards", 'callback_data' => 'inv_myrewards']],
            [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'inv_rewards']],
        ]);
}

// A reward tapped from the Rewards screen - route it into redemption.
// (inviteRedeemReward lives in webhook.php where the promo engine is available.)
function inviteClaimReward($userId, $rewardId) {
    global $tg, $referral;
    if (!inviteGuard($userId)) return;
    $rw = $referral->reward($rewardId);
    if (!$rw || (int) $rw['telegram_id'] !== (int) $userId) {
        $tg->sendMessage($userId, "Sorry, that reward could not be found.");
        return;
    }
    if ($rw['status'] !== 'earned') { inviteViewMyRewards($userId); return; }
    if (function_exists('inviteRedeemReward')) { inviteRedeemReward($userId, (int) $rewardId); return; }
    inviteRewardUnlocked($userId, $rw);
}

// Legacy callbacks from older messages (Start Now / Save / Choose Date) now all
// funnel into the single redeem flow, so buttons left in old chats still work.
function inviteRewardStartMode($userId, $rewardId, $mode) {
    inviteClaimReward($userId, $rewardId);
}
function inviteRewardChooseDate($userId, $rewardId) {
    inviteClaimReward($userId, $rewardId);
}
function inviteRewardSaveDate($userId, $text, $state) {
    global $db;
    $db->setState($userId, 'idle', []);
    inviteClaimReward($userId, (int) ($state['data']['reward_id'] ?? 0));
}

// Screen 10 - View My Rewards with status.
function inviteViewMyRewards($userId) {
    global $tg, $referral;
    if (!inviteGuard($userId)) return;
    $referral->checkMilestones($userId);
    $rewards = $referral->rewardsFor($userId);
    if (!$rewards) {
        $tg->sendInlineButtons($userId,
            "\xF0\x9F\x93\x8B <b>My Rewards</b>\n\nYou haven't earned any rewards yet. Keep inviting friends!",
            [[['text' => "\xF0\x9F\x94\x97 My Referral Link", 'callback_data' => 'inv_link']],
             [['text' => "\xE2\xAC\x85\xEF\xB8\x8F Back", 'callback_data' => 'invite']]]);
        return;
    }
    $labels = [
        'earned'   => "\xF0\x9F\x8E\x81 Ready to redeem",
        'redeemed' => "\xE2\x9C\x85 Redeemed - scheduled",
        'approved' => "\xE2\x9C\x85 Active",
        'rejected' => "\xE2\x9D\x8C Not approved",
    ];
    $msg = "\xF0\x9F\x93\x8B <b>My Rewards</b>\n\n";
    $rows = [];
    foreach ($rewards as $rw) {
        $st = $labels[$rw['status']] ?? ucfirst((string) $rw['status']);
        $msg .= "\xF0\x9F\x8E\x81 <b>" . htmlspecialchars($rw['title']) . "</b>\n";
        $msg .= "   Status: {$st}\n";
        if ($rw['start_date']) $msg .= "   Start: " . htmlspecialchars($rw['start_date']) . ($rw['end_date'] ? " \xE2\x86\x92 " . htmlspecialchars($rw['end_date']) : '') . "\n";
        $msg .= "\n";
        if ($rw['status'] === 'earned') {
            $rows[] = [['text' => "\xF0\x9F\x8E\x89 Redeem: " . mb_strimwidth($rw['title'], 0, 26, '...'),
                       'callback_data' => 'inv_redeem_' . $rw['id']]];
        }
    }
    $rows[] = [['text' => "\xF0\x9F\x8E\x81 Rewards", 'callback_data' => 'inv_rewards']];
    $rows[] = [['text' => "\xF0\x9F\x8F\xA0 Main Menu", 'callback_data' => 'main_menu']];
    $tg->sendInlineButtons($userId, $msg, $rows);
}
