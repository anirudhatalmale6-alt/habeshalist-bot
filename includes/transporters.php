<?php
/**
 * transporters.php - data + logic layer for the HabeshaList Verified Transporter
 * module (Phase 1 MVP).
 *
 * DESIGN (same guarantees as the Digital Screens module)
 * Fully SELF-CONTAINED and additive: it only CREATEs its own three tables
 * (transporters / transporter_posts / transporter_ratings) and never touches a
 * table the classifieds bot, the promotions engine or the screens module owns.
 * If the module is switched off, nothing else in the bot changes.
 *
 * It works against a SQLite3 handle the CALLER passes in, so the same code is
 * reused by the bot, the admin panel and the PUBLIC WEBSITE PAGE - which is what
 * makes "bot and website must use the same transporter data source" true by
 * construction rather than by a sync job.
 *
 * TWO IDENTIFIERS, ON PURPOSE (spec section 5)
 *   - transporters.id   = the permanent INTERNAL transporter_id. Every post,
 *                         rating and admin action is keyed on THIS. It never
 *                         changes and never depends on the public username.
 *   - transporters.username = the PUBLIC @handle (e.g. @AbebeTravel). It is what
 *                         a customer types and what the website URL uses. It can
 *                         be changed (with admin approval) without orphaning a
 *                         single row of history.
 *
 * PRIVACY: hl_tr_public() is the ONLY shape that should ever reach a public page.
 * It deliberately drops telegram_id, the internal id, admin notes and anything
 * payment-related (spec section 6).
 */

// Verification lifecycle. 'needs_review' is NOT a status - it is the
// admin_review_required flag on an otherwise approved transporter, so a flagged
// transporter keeps working until an admin actually decides (spec section 14).
const HL_TR_STATUSES = ['pending', 'approved', 'rejected', 'suspended'];

// Only 1- and 2-star ratings count as "low" (spec section 14).
const HL_TR_LOW_STAR_MAX = 2;
// The Nth low rating from the Nth DISTINCT reviewer raises the review flag.
const HL_TR_LOW_DISTINCT_TRIGGER = 3;

// Public usernames we never hand out.
const HL_TR_RESERVED_USERNAMES = [
    'habeshalist', 'habesha_list', 'habesha', 'admin', 'administrator', 'root',
    'support', 'help', 'official', 'staff', 'team', 'moderator', 'mod', 'system',
    'bot', 'telegram', 'transporter', 'transporters', 'verified', 'habeshalistbot',
    'habeshalistofficial', 'info', 'contact', 'billing', 'payments', 'security',
    'null', 'undefined', 'me', 'you', 'new', 'edit', 'delete', 'login', 'signup',
];

// Crude profanity/abuse screen for public handles. Substring match, lowercased.
const HL_TR_BLOCKED_USERNAME_PARTS = [
    'fuck', 'shit', 'bitch', 'cunt', 'nigg', 'rape', 'nazi', 'porn', 'sex',
    'whore', 'slut', 'dick', 'penis', 'vagina', 'asshole', 'faggot', 'retard',
];

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

/**
 * Create the module's tables if they do not exist. Safe to call on every
 * request - a no-op once the tables are present.
 */
function hl_tr_ensure_schema(SQLite3 $db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS transporters (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,  -- PERMANENT internal transporter_id
            telegram_id         INTEGER NOT NULL,                   -- captured automatically; account reference
            tg_username         TEXT DEFAULT '',                    -- captured automatically (Telegram @handle)
            tg_display_name     TEXT DEFAULT '',                    -- captured automatically
            full_name           TEXT DEFAULT '',
            phone_number        TEXT DEFAULT '',
            photo_file_id       TEXT DEFAULT '',                    -- Telegram file_id (bot re-sends)
            photo_path          TEXT DEFAULT '',                    -- saved copy the WEBSITE can serve
            origin              TEXT DEFAULT '',                    -- usual origin, e.g. DMV
            destination         TEXT DEFAULT '',                    -- usual destination, e.g. Addis Ababa
            contact_method      TEXT DEFAULT '',                    -- how customers should reach them
            availability_note   TEXT DEFAULT '',                    -- optional \"currently available\" line
            username            TEXT,                               -- PUBLIC @handle; NULL until created
            username_lower      TEXT,                               -- case-insensitive uniqueness key
            username_pending    TEXT DEFAULT '',                    -- requested change awaiting admin approval
            verification_status TEXT DEFAULT 'pending',             -- pending | approved | rejected | suspended
            agreed_at           TEXT,                               -- when they accepted the disclaimer
            admin_review_required INTEGER DEFAULT 0,                -- raised by the 3-distinct-low-rating rule
            admin_notes         TEXT DEFAULT '',                    -- PRIVATE - never exposed publicly
            warned_at           TEXT,                               -- last time an admin sent a warning
            rating_sum          INTEGER DEFAULT 0,                  -- denormalised for fast website reads
            rating_count        INTEGER DEFAULT 0,
            free_posts_used     INTEGER DEFAULT 0,                  -- against the admin's free intro allowance
            created_at          TEXT DEFAULT (datetime('now')),
            approved_at         TEXT
        )");
    $db->exec("
        CREATE TABLE IF NOT EXISTS transporter_posts (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            transporter_id INTEGER NOT NULL,          -- INTERNAL id, never the username
            origin         TEXT DEFAULT '',
            destination    TEXT DEFAULT '',
            travel_date    TEXT DEFAULT '',           -- free text or YYYY-MM-DD
            space          TEXT DEFAULT '',           -- available space, e.g. \"2 suitcases, 20kg\"
            price          TEXT DEFAULT '',           -- the TRANSPORTER's price (their own terms)
            contact_method TEXT DEFAULT '',
            ad_fee         REAL DEFAULT 0,            -- advertising fee FROZEN at checkout
            payment_method TEXT DEFAULT '',           -- card | zelle | cashapp | free
            payment_status TEXT DEFAULT 'unpaid',     -- unpaid | approved | pending_review | rejected
            payment_ref    TEXT DEFAULT '',           -- Stripe id / manual receipt
            payment_proof  TEXT DEFAULT '',           -- Telegram file_id of the screenshot
            tg_message_id  INTEGER,                   -- set ONCE on publish; the duplicate guard
            published_at   TEXT,
            post_status    TEXT DEFAULT 'draft',      -- draft | pending_payment | pending_review | published | rejected | cancelled
            created_at     TEXT DEFAULT (datetime('now'))
        )");
    $db->exec("
        CREATE TABLE IF NOT EXISTS transporter_ratings (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            transporter_id INTEGER NOT NULL,          -- INTERNAL id (customer searched by username)
            rating         INTEGER NOT NULL,          -- 1..5
            comment        TEXT DEFAULT '',           -- optional
            reviewer_tid   INTEGER,                   -- reviewer's Telegram user id
            created_at     TEXT DEFAULT (datetime('now'))
        )");
    // Case-insensitive uniqueness is enforced by the index on the lowered copy.
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_tr_username_lower ON transporters(username_lower)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_tr_status ON transporters(verification_status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_tr_tid ON transporters(telegram_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_trp_transporter ON transporter_posts(transporter_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_trr_transporter ON transporter_ratings(transporter_id)");
    hl_tr_migrate($db);
}

/**
 * Add any column introduced after the tables already existed. SQLite has no
 * "ADD COLUMN IF NOT EXISTS", so we diff against PRAGMA table_info and ALTER the
 * gaps. Idempotent - a no-op once every column is present.
 */
function hl_tr_migrate(SQLite3 $db) {
    $wanted = [
        'transporters' => [
            'tg_username'           => "TEXT DEFAULT ''",
            'tg_display_name'       => "TEXT DEFAULT ''",
            'photo_path'            => "TEXT DEFAULT ''",
            'contact_method'        => "TEXT DEFAULT ''",
            'availability_note'     => "TEXT DEFAULT ''",
            'username_pending'      => "TEXT DEFAULT ''",
            'agreed_at'             => "TEXT",
            'admin_review_required' => "INTEGER DEFAULT 0",
            'admin_notes'           => "TEXT DEFAULT ''",
            'warned_at'             => "TEXT",
            'rating_sum'            => "INTEGER DEFAULT 0",
            'rating_count'          => "INTEGER DEFAULT 0",
            'free_posts_used'       => "INTEGER DEFAULT 0",
            'approved_at'           => "TEXT",
        ],
        'transporter_posts' => [
            'ad_fee'         => "REAL DEFAULT 0",
            'payment_method' => "TEXT DEFAULT ''",
            'payment_ref'    => "TEXT DEFAULT ''",
            'payment_proof'  => "TEXT DEFAULT ''",
            'tg_message_id'  => "INTEGER",
            'published_at'   => "TEXT",
        ],
    ];
    foreach ($wanted as $table => $cols) {
        $have = [];
        $res = $db->query("PRAGMA table_info({$table})");
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $have[$r['name']] = true; }
        if (!$have) continue;                       // table absent - CREATE covered it
        foreach ($cols as $name => $decl) {
            if (!isset($have[$name])) @$db->exec("ALTER TABLE {$table} ADD COLUMN {$name} {$decl}");
        }
    }
}

// ---------------------------------------------------------------------------
// Admin settings (spec section 10 - the fee must NEVER be hard-coded)
// ---------------------------------------------------------------------------

/**
 * Every transporter setting with its default, read through the bot's own
 * settings table. `$get` is any callable that takes (key, default) - the bot
 * passes $db->getSetting(...), the panel passes hl_get_setting.
 */
function hl_tr_settings(callable $get) {
    return [
        'enabled'        => ((string) $get('tr_module_enabled', '1')) === '1',
        'ads_enabled'    => ((string) $get('tr_ads_enabled', '1')) === '1',
        'fee'            => (float) $get('tr_ad_fee', '5'),
        'currency'       => strtoupper((string) $get('tr_currency', 'USD')) ?: 'USD',
        'free_posts'     => max(0, (int) $get('tr_free_posts', '0')),
        'group_chat_id'  => (string) $get('sched_group_chat_id', ''),
        'site_url'       => rtrim((string) $get('tr_site_url', 'https://habeshalist.com'), '/'),
    ];
}

/** "$5", "$5.50" - matches the money style used across the bot. */
function hl_tr_money($amount, $currency = 'USD') {
    $s = number_format((float) $amount, 2);
    if (substr($s, -3) === '.00') $s = substr($s, 0, -3);
    return ($currency === 'USD' ? '$' : '') . $s . ($currency === 'USD' ? '' : ' ' . $currency);
}

/**
 * What this transporter should pay for their NEXT post: the current admin fee,
 * or 0 while they still have free introductory posts left. Returns
 * ['fee' => float, 'free' => bool, 'free_left' => int].
 */
function hl_tr_fee_for(array $tr, array $settings) {
    if (!$settings['ads_enabled']) {
        return ['fee' => 0.0, 'free' => true, 'free_left' => 0, 'reason' => 'ads_off'];
    }
    $left = max(0, $settings['free_posts'] - (int) ($tr['free_posts_used'] ?? 0));
    if ($left > 0) {
        return ['fee' => 0.0, 'free' => true, 'free_left' => $left, 'reason' => 'intro'];
    }
    return ['fee' => (float) $settings['fee'], 'free' => ((float) $settings['fee']) <= 0, 'free_left' => 0, 'reason' => 'fee'];
}

// ---------------------------------------------------------------------------
// Public usernames (spec section 5)
// ---------------------------------------------------------------------------

/** Strip a leading @ and surrounding whitespace. */
function hl_tr_normalize_username($u) {
    return ltrim(trim((string) $u), '@');
}

/**
 * Validate a requested public username against every rule in the spec.
 * Returns '' when valid, otherwise a human-readable reason.
 * Pass $db + $exceptId to also check case-insensitive uniqueness.
 */
function hl_tr_username_error($username, SQLite3 $db = null, $exceptId = 0) {
    $u = hl_tr_normalize_username($username);

    if ($u === '')                       return 'Please send a username.';
    if (preg_match('/\s/', $u))          return 'No spaces allowed - use letters, numbers or underscore.';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $u)) return 'Only letters, numbers and underscore ( _ ) are allowed.';
    if (strlen($u) < 4)                  return 'Too short - please use at least 4 characters.';
    if (strlen($u) > 24)                 return 'Too long - please keep it to 24 characters or fewer.';
    if (!preg_match('/[A-Za-z]/', $u))   return 'Please include at least one letter.';

    $low = strtolower($u);
    if (in_array($low, HL_TR_RESERVED_USERNAMES, true)) return 'That name is reserved. Please choose another.';
    foreach (HL_TR_RESERVED_USERNAMES as $r) {
        // Block near-misses like "habeshalist_admin" that impersonate the platform.
        if ($r !== '' && strlen($r) >= 6 && strpos($low, $r) !== false) {
            return 'That name is reserved. Please choose another.';
        }
    }
    foreach (HL_TR_BLOCKED_USERNAME_PARTS as $bad) {
        if (strpos($low, $bad) !== false) return 'That username is not allowed. Please choose another.';
    }

    if ($db instanceof SQLite3 && !hl_tr_username_available($db, $u, $exceptId)) {
        return 'Sorry, @' . $u . ' is already taken. Please try another.';
    }
    return '';
}

/** Case-insensitive availability check. */
function hl_tr_username_available(SQLite3 $db, $username, $exceptId = 0) {
    $low = strtolower(hl_tr_normalize_username($username));
    if ($low === '') return false;
    $stmt = $db->prepare('SELECT id FROM transporters WHERE username_lower = :u AND id <> :x LIMIT 1');
    $stmt->bindValue(':u', $low, SQLITE3_TEXT);
    $stmt->bindValue(':x', (int) $exceptId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return !$row;
}

/**
 * Claim a public username for a transporter. Returns '' on success, or a reason.
 * The UNIQUE index is the real race guard - two people typing the same handle at
 * the same moment can't both win.
 */
function hl_tr_set_username(SQLite3 $db, $transporterId, $username) {
    $u = hl_tr_normalize_username($username);
    $err = hl_tr_username_error($u, $db, (int) $transporterId);
    if ($err !== '') return $err;

    $stmt = $db->prepare("UPDATE transporters SET username = :u, username_lower = :l, username_pending = '' WHERE id = :id");
    $stmt->bindValue(':u', $u, SQLITE3_TEXT);
    $stmt->bindValue(':l', strtolower($u), SQLITE3_TEXT);
    $stmt->bindValue(':id', (int) $transporterId, SQLITE3_INTEGER);
    $ok = @$stmt->execute();
    if (!$ok) return 'Sorry, @' . $u . ' was just taken. Please try another.';
    return '';
}

/** Record a username CHANGE request; admin approves it before it goes live. */
function hl_tr_request_username_change(SQLite3 $db, $transporterId, $username) {
    $u = hl_tr_normalize_username($username);
    $err = hl_tr_username_error($u, $db, (int) $transporterId);
    if ($err !== '') return $err;
    $stmt = $db->prepare('UPDATE transporters SET username_pending = :u WHERE id = :id');
    $stmt->bindValue(':u', $u, SQLITE3_TEXT);
    $stmt->bindValue(':id', (int) $transporterId, SQLITE3_INTEGER);
    $stmt->execute();
    return '';
}

/** Admin decision on a pending username change. */
function hl_tr_resolve_username_change(SQLite3 $db, $transporterId, $approve) {
    $tr = hl_tr_by_id($db, $transporterId);
    if (!$tr) return 'Transporter not found.';
    $pending = hl_tr_normalize_username($tr['username_pending'] ?? '');
    if ($pending === '') return 'No username change is pending.';
    if (!$approve) {
        $stmt = $db->prepare("UPDATE transporters SET username_pending = '' WHERE id = :id");
        $stmt->bindValue(':id', (int) $transporterId, SQLITE3_INTEGER);
        $stmt->execute();
        return '';
    }
    return hl_tr_set_username($db, $transporterId, $pending);
}

// ---------------------------------------------------------------------------
// Transporter records
// ---------------------------------------------------------------------------

/**
 * Create (or refresh) a pending application for a Telegram user. A user only
 * ever has ONE transporter record - re-applying after a rejection reuses the
 * same permanent internal id rather than minting a second one.
 */
function hl_tr_apply(SQLite3 $db, array $d) {
    $tid = (int) ($d['telegram_id'] ?? 0);
    if ($tid <= 0) return 0;

    $existing = hl_tr_by_telegram($db, $tid);
    $cols = [
        'telegram_id'     => $tid,
        'tg_username'     => (string) ($d['tg_username'] ?? ''),
        'tg_display_name' => (string) ($d['tg_display_name'] ?? ''),
        'full_name'       => (string) ($d['full_name'] ?? ''),
        'phone_number'    => (string) ($d['phone_number'] ?? ''),
        'photo_file_id'   => (string) ($d['photo_file_id'] ?? ''),
        'photo_path'      => (string) ($d['photo_path'] ?? ''),
        'origin'          => (string) ($d['origin'] ?? ''),
        'destination'     => (string) ($d['destination'] ?? ''),
        'contact_method'  => (string) ($d['contact_method'] ?? ''),
        'agreed_at'       => (string) ($d['agreed_at'] ?? gmdate('Y-m-d H:i:s')),
    ];

    if ($existing) {
        $set = [];
        foreach ($cols as $k => $v) { $set[] = "{$k} = :{$k}"; }
        // Re-applying puts them back in the queue, but never rewrites an
        // approved record's status or their earned username/ratings.
        if (in_array($existing['verification_status'], ['pending', 'rejected'], true)) {
            $set[] = "verification_status = 'pending'";
        }
        $stmt = $db->prepare('UPDATE transporters SET ' . implode(', ', $set) . ' WHERE id = :id');
        foreach ($cols as $k => $v) { $stmt->bindValue(':' . $k, $v, SQLITE3_TEXT); }
        $stmt->bindValue(':id', (int) $existing['id'], SQLITE3_INTEGER);
        $stmt->execute();
        return (int) $existing['id'];
    }

    $keys = array_keys($cols);
    $stmt = $db->prepare('INSERT INTO transporters (' . implode(',', $keys) . ", verification_status)
                          VALUES (:" . implode(',:', $keys) . ", 'pending')");
    foreach ($cols as $k => $v) {
        $stmt->bindValue(':' . $k, $v, $k === 'telegram_id' ? SQLITE3_INTEGER : SQLITE3_TEXT);
    }
    if (!$stmt->execute()) return 0;
    return (int) $db->lastInsertRowID();
}

function hl_tr_by_id(SQLite3 $db, $id) {
    $stmt = $db->prepare('SELECT * FROM transporters WHERE id = :id');
    $stmt->bindValue(':id', (int) $id, SQLITE3_INTEGER);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}

function hl_tr_by_telegram(SQLite3 $db, $telegramId) {
    $stmt = $db->prepare('SELECT * FROM transporters WHERE telegram_id = :t ORDER BY id ASC LIMIT 1');
    $stmt->bindValue(':t', (int) $telegramId, SQLITE3_INTEGER);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}

/** Look a transporter up by their PUBLIC @username (case-insensitive). */
function hl_tr_by_username(SQLite3 $db, $username) {
    $low = strtolower(hl_tr_normalize_username($username));
    if ($low === '') return null;
    $stmt = $db->prepare('SELECT * FROM transporters WHERE username_lower = :u LIMIT 1');
    $stmt->bindValue(':u', $low, SQLITE3_TEXT);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}

/** Update an arbitrary set of columns on a transporter (admin edit, profile). */
function hl_tr_update(SQLite3 $db, $id, array $fields) {
    $allowed = [
        'full_name', 'phone_number', 'photo_file_id', 'photo_path', 'origin',
        'destination', 'contact_method', 'availability_note', 'admin_notes',
        'tg_username', 'tg_display_name',
    ];
    $set = []; $vals = [];
    foreach ($fields as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        $set[] = "{$k} = :{$k}";
        $vals[$k] = (string) $v;
    }
    if (!$set) return false;
    $stmt = $db->prepare('UPDATE transporters SET ' . implode(', ', $set) . ' WHERE id = :id');
    foreach ($vals as $k => $v) { $stmt->bindValue(':' . $k, $v, SQLITE3_TEXT); }
    $stmt->bindValue(':id', (int) $id, SQLITE3_INTEGER);
    return (bool) $stmt->execute();
}

/**
 * Move a transporter through the verification lifecycle. Approving stamps
 * approved_at (which is what unlocks username creation) and clears any review
 * flag; suspending NEVER deletes posts, ratings or history (spec section 14).
 */
function hl_tr_set_status(SQLite3 $db, $id, $status, $adminNote = null) {
    if (!in_array($status, HL_TR_STATUSES, true)) return false;

    $sql = 'UPDATE transporters SET verification_status = :s';
    if ($status === 'approved') $sql .= ", approved_at = COALESCE(approved_at, datetime('now')), admin_review_required = 0";
    if ($adminNote !== null)    $sql .= ', admin_notes = :n';
    $sql .= ' WHERE id = :id';

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':s', $status, SQLITE3_TEXT);
    if ($adminNote !== null) $stmt->bindValue(':n', (string) $adminNote, SQLITE3_TEXT);
    $stmt->bindValue(':id', (int) $id, SQLITE3_INTEGER);
    return (bool) $stmt->execute();
}

/** Clear the review flag after an admin chooses "Keep Active" or "Send Warning". */
function hl_tr_clear_review(SQLite3 $db, $id, $warned = false) {
    $sql = 'UPDATE transporters SET admin_review_required = 0';
    if ($warned) $sql .= ", warned_at = datetime('now')";
    $sql .= ' WHERE id = :id';
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':id', (int) $id, SQLITE3_INTEGER);
    return (bool) $stmt->execute();
}

/**
 * What can this transporter do right now? Returns
 * ['can_post' => bool, 'state' => string, 'message' => string] where `state` is
 * one of: none | pending | rejected | suspended | no_username | ready.
 * This is the single gate used by "Post Available Space" (spec section 8).
 */
function hl_tr_eligibility($tr) {
    if (!$tr) {
        return ['can_post' => false, 'state' => 'none',
                'message' => "You're not a Verified Transporter yet. Apply first - it only takes a minute."];
    }
    $status = (string) ($tr['verification_status'] ?? 'pending');
    if ($status === 'pending') {
        return ['can_post' => false, 'state' => 'pending',
                'message' => "Your application is under review. We'll message you here as soon as it's approved."];
    }
    if ($status === 'rejected') {
        return ['can_post' => false, 'state' => 'rejected',
                'message' => "Your application wasn't approved. You can apply again with updated details, or contact support."];
    }
    if ($status === 'suspended') {
        return ['can_post' => false, 'state' => 'suspended',
                'message' => "Your transporter account is currently suspended, so new transportation posts are paused. Please contact support."];
    }
    if (hl_tr_normalize_username($tr['username'] ?? '') === '') {
        return ['can_post' => false, 'state' => 'no_username',
                'message' => "Almost there! Create your public HabeshaList username first - it's the name customers will see."];
    }
    return ['can_post' => true, 'state' => 'ready', 'message' => ''];
}

// ---------------------------------------------------------------------------
// Directory queries (bot "Find a Transporter" + the website page)
// ---------------------------------------------------------------------------

/**
 * Approved, username-confirmed transporters, newest-approved first. Suspended
 * ones are excluded, which is exactly what "suspension hides the website
 * profile" means (spec section 6).
 */
function hl_tr_directory(SQLite3 $db, $limit = 200) {
    $out = [];
    $stmt = $db->prepare("SELECT * FROM transporters
                          WHERE verification_status = 'approved'
                            AND username_lower IS NOT NULL AND username_lower <> ''
                          ORDER BY (CASE WHEN rating_count > 0 THEN 0 ELSE 1 END),
                                   (CAST(rating_sum AS REAL) / MAX(rating_count,1)) DESC,
                                   id ASC
                          LIMIT :l");
    $stmt->bindValue(':l', (int) $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $out[] = $r; }
    return $out;
}

/**
 * MVP route search (spec section 7): match origin/destination loosely so "DMV"
 * finds "DMV area" and an empty term matches everything. Deliberately simple -
 * no advanced marketplace matching until there's inventory to justify it.
 */
function hl_tr_search(SQLite3 $db, $from = '', $to = '', $limit = 25) {
    $from = trim((string) $from);
    $to   = trim((string) $to);
    $all  = hl_tr_directory($db, 500);

    $match = function ($needle, $hay) {
        $needle = strtolower(trim($needle));
        $hay    = strtolower(trim($hay));
        if ($needle === '' || $needle === 'any' || $needle === '*') return true;
        if ($hay === '') return false;
        if (strpos($hay, $needle) !== false || strpos($needle, $hay) !== false) return true;
        // Word-level overlap so "Addis Ababa" matches "Addis".
        foreach (preg_split('/[^a-z0-9]+/', $needle) as $w) {
            if (strlen($w) >= 3 && strpos($hay, $w) !== false) return true;
        }
        return false;
    };

    $hits = [];
    foreach ($all as $t) {
        if ($match($from, $t['origin'] ?? '') && $match($to, $t['destination'] ?? '')) {
            $hits[] = $t;
            if (count($hits) >= $limit) break;
        }
    }
    return $hits;
}

/** Count transporters per verification status, plus the needs-review bucket. */
function hl_tr_counts(SQLite3 $db) {
    $out = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'suspended' => 0, 'needs_review' => 0];
    $res = $db->query('SELECT verification_status s, COUNT(*) c FROM transporters GROUP BY verification_status');
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
        if (isset($out[$r['s']])) $out[$r['s']] = (int) $r['c'];
    }
    $r = $db->querySingle('SELECT COUNT(*) FROM transporters WHERE admin_review_required = 1');
    $out['needs_review'] = (int) $r;
    return $out;
}

/** Transporters in one admin tab. `needs_review` cuts across the statuses. */
function hl_tr_list(SQLite3 $db, $tab = 'pending', $limit = 200) {
    $out = [];
    if ($tab === 'needs_review') {
        $sql = 'SELECT * FROM transporters WHERE admin_review_required = 1 ORDER BY id DESC LIMIT :l';
        $stmt = $db->prepare($sql);
    } else {
        if (!in_array($tab, HL_TR_STATUSES, true)) $tab = 'pending';
        $stmt = $db->prepare('SELECT * FROM transporters WHERE verification_status = :s ORDER BY id DESC LIMIT :l');
        $stmt->bindValue(':s', $tab, SQLITE3_TEXT);
    }
    $stmt->bindValue(':l', (int) $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $out[] = $r; }
    return $out;
}

// ---------------------------------------------------------------------------
// Ratings (spec sections 13-15)
// ---------------------------------------------------------------------------

/** Average as a float (0.0 when unrated) - the single source for bot + website. */
function hl_tr_avg($tr) {
    $c = (int) ($tr['rating_count'] ?? 0);
    if ($c <= 0) return 0.0;
    return round(((int) ($tr['rating_sum'] ?? 0)) / $c, 1);
}

/** "4.5 (12)" or "No ratings yet". */
function hl_tr_rating_label($tr) {
    $c = (int) ($tr['rating_count'] ?? 0);
    if ($c <= 0) return 'No ratings yet';
    return number_format(hl_tr_avg($tr), 1) . ' (' . $c . ' rating' . ($c === 1 ? '' : 's') . ')';
}

/** Filled/empty stars for a rating, as a short display string. */
function hl_tr_stars($avg) {
    $n = (int) round((float) $avg);
    $n = max(0, min(5, $n));
    return str_repeat("\xE2\xAD\x90", $n) . str_repeat("\xE2\x98\x86", 5 - $n);
}

/**
 * Save a rating and recalculate the transporter's average in the same step, so
 * the website (which reads the denormalised columns) is never stale.
 *
 * Returns ['ok' => bool, 'flagged' => bool, 'low_distinct' => int, 'error' => string].
 * `flagged` is true on the transition that raises admin_review_required, i.e.
 * when the THIRD distinct Telegram user leaves a 1- or 2-star rating.
 */
function hl_tr_add_rating(SQLite3 $db, $transporterId, $rating, $comment = '', $reviewerTid = 0) {
    $rating = (int) $rating;
    if ($rating < 1 || $rating > 5) return ['ok' => false, 'flagged' => false, 'low_distinct' => 0, 'error' => 'Rating must be 1 to 5.'];
    $tr = hl_tr_by_id($db, $transporterId);
    if (!$tr) return ['ok' => false, 'flagged' => false, 'low_distinct' => 0, 'error' => 'Transporter not found.'];

    $stmt = $db->prepare('INSERT INTO transporter_ratings (transporter_id, rating, comment, reviewer_tid)
                          VALUES (:t, :r, :c, :v)');
    $stmt->bindValue(':t', (int) $transporterId, SQLITE3_INTEGER);
    $stmt->bindValue(':r', $rating, SQLITE3_INTEGER);
    $stmt->bindValue(':c', (string) $comment, SQLITE3_TEXT);
    $stmt->bindValue(':v', (int) $reviewerTid, SQLITE3_INTEGER);
    if (!$stmt->execute()) return ['ok' => false, 'flagged' => false, 'low_distinct' => 0, 'error' => 'Could not save the rating.'];

    hl_tr_recalc_rating($db, $transporterId);

    // The low-rating rule counts DISTINCT reviewers, not ratings - three 1-star
    // ratings from the same person must never flag a transporter.
    $low = hl_tr_low_distinct_count($db, $transporterId);
    $wasFlagged = (int) ($tr['admin_review_required'] ?? 0) === 1;
    $flagged = false;
    if (!$wasFlagged && $low >= HL_TR_LOW_DISTINCT_TRIGGER) {
        $u = $db->prepare('UPDATE transporters SET admin_review_required = 1 WHERE id = :id');
        $u->bindValue(':id', (int) $transporterId, SQLITE3_INTEGER);
        $u->execute();
        $flagged = true;                    // NOTE: flag only - never an auto-suspend.
    }
    return ['ok' => true, 'flagged' => $flagged, 'low_distinct' => $low, 'error' => ''];
}

/** How many DISTINCT Telegram users have left this transporter a 1-2 star rating. */
function hl_tr_low_distinct_count(SQLite3 $db, $transporterId) {
    $stmt = $db->prepare('SELECT COUNT(DISTINCT reviewer_tid) c FROM transporter_ratings
                          WHERE transporter_id = :t AND rating <= :m AND reviewer_tid IS NOT NULL AND reviewer_tid <> 0');
    $stmt->bindValue(':t', (int) $transporterId, SQLITE3_INTEGER);
    $stmt->bindValue(':m', HL_TR_LOW_STAR_MAX, SQLITE3_INTEGER);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return (int) ($r['c'] ?? 0);
}

/** Recompute the denormalised rating_sum/rating_count from the ratings table. */
function hl_tr_recalc_rating(SQLite3 $db, $transporterId) {
    $stmt = $db->prepare('SELECT COALESCE(SUM(rating),0) s, COUNT(*) c FROM transporter_ratings WHERE transporter_id = :t');
    $stmt->bindValue(':t', (int) $transporterId, SQLITE3_INTEGER);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: ['s' => 0, 'c' => 0];
    $u = $db->prepare('UPDATE transporters SET rating_sum = :s, rating_count = :c WHERE id = :id');
    $u->bindValue(':s', (int) $r['s'], SQLITE3_INTEGER);
    $u->bindValue(':c', (int) $r['c'], SQLITE3_INTEGER);
    $u->bindValue(':id', (int) $transporterId, SQLITE3_INTEGER);
    $u->execute();
    return ['sum' => (int) $r['s'], 'count' => (int) $r['c']];
}

/** Recent ratings for a transporter (admin review + website profile). */
function hl_tr_ratings(SQLite3 $db, $transporterId, $limit = 25) {
    $out = [];
    $stmt = $db->prepare('SELECT * FROM transporter_ratings WHERE transporter_id = :t ORDER BY id DESC LIMIT :l');
    $stmt->bindValue(':t', (int) $transporterId, SQLITE3_INTEGER);
    $stmt->bindValue(':l', (int) $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $out[] = $r; }
    return $out;
}

// ---------------------------------------------------------------------------
// Availability posts (spec sections 8-12)
// ---------------------------------------------------------------------------

/** Create an availability post. Always starts as a draft owned by the INTERNAL id. */
function hl_tr_create_post(SQLite3 $db, array $d) {
    $cols = [
        'transporter_id' => (int) ($d['transporter_id'] ?? 0),
        'origin'         => (string) ($d['origin'] ?? ''),
        'destination'    => (string) ($d['destination'] ?? ''),
        'travel_date'    => (string) ($d['travel_date'] ?? ''),
        'space'          => (string) ($d['space'] ?? ''),
        'price'          => (string) ($d['price'] ?? ''),
        'contact_method' => (string) ($d['contact_method'] ?? ''),
        'ad_fee'         => (float)  ($d['ad_fee'] ?? 0),
        'payment_method' => (string) ($d['payment_method'] ?? ''),
        'payment_status' => (string) ($d['payment_status'] ?? 'unpaid'),
        'payment_ref'    => (string) ($d['payment_ref'] ?? ''),
        'payment_proof'  => (string) ($d['payment_proof'] ?? ''),
        'post_status'    => (string) ($d['post_status'] ?? 'draft'),
    ];
    if ($cols['transporter_id'] <= 0) return 0;

    $keys = array_keys($cols);
    $stmt = $db->prepare('INSERT INTO transporter_posts (' . implode(',', $keys) . ')
                          VALUES (:' . implode(',:', $keys) . ')');
    foreach ($cols as $k => $v) {
        if ($k === 'transporter_id')  $stmt->bindValue(':' . $k, (int) $v, SQLITE3_INTEGER);
        elseif ($k === 'ad_fee')      $stmt->bindValue(':' . $k, (float) $v, SQLITE3_FLOAT);
        else                          $stmt->bindValue(':' . $k, (string) $v, SQLITE3_TEXT);
    }
    if (!$stmt->execute()) return 0;
    return (int) $db->lastInsertRowID();
}

function hl_tr_post_by_id(SQLite3 $db, $id) {
    $stmt = $db->prepare('SELECT * FROM transporter_posts WHERE id = :id');
    $stmt->bindValue(':id', (int) $id, SQLITE3_INTEGER);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}

/** Update a post's payment/publication fields. */
function hl_tr_update_post(SQLite3 $db, $id, array $fields) {
    $allowed = ['origin', 'destination', 'travel_date', 'space', 'price', 'contact_method',
                'ad_fee', 'payment_method', 'payment_status', 'payment_ref', 'payment_proof',
                'post_status', 'tg_message_id', 'published_at'];
    $set = []; $vals = [];
    foreach ($fields as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        $set[] = "{$k} = :{$k}";
        $vals[$k] = $v;
    }
    if (!$set) return false;
    $stmt = $db->prepare('UPDATE transporter_posts SET ' . implode(', ', $set) . ' WHERE id = :id');
    foreach ($vals as $k => $v) {
        if ($k === 'tg_message_id') $stmt->bindValue(':' . $k, (int) $v, SQLITE3_INTEGER);
        elseif ($k === 'ad_fee')    $stmt->bindValue(':' . $k, (float) $v, SQLITE3_FLOAT);
        else                        $stmt->bindValue(':' . $k, (string) $v, SQLITE3_TEXT);
    }
    $stmt->bindValue(':id', (int) $id, SQLITE3_INTEGER);
    return (bool) $stmt->execute();
}

/** Posts belonging to one transporter (dashboard + admin detail). */
function hl_tr_posts(SQLite3 $db, $transporterId, $limit = 50) {
    $out = [];
    $stmt = $db->prepare('SELECT * FROM transporter_posts WHERE transporter_id = :t ORDER BY id DESC LIMIT :l');
    $stmt->bindValue(':t', (int) $transporterId, SQLITE3_INTEGER);
    $stmt->bindValue(':l', (int) $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $out[] = $r; }
    return $out;
}

/** Posts waiting on an admin to verify a payment screenshot (spec section 11). */
function hl_tr_pending_payment_posts(SQLite3 $db, $limit = 100) {
    $out = [];
    $stmt = $db->prepare("SELECT p.*, t.username, t.full_name, t.telegram_id
                          FROM transporter_posts p
                          JOIN transporters t ON t.id = p.transporter_id
                          WHERE p.post_status = 'pending_review'
                          ORDER BY p.id DESC LIMIT :l");
    $stmt->bindValue(':l', (int) $limit, SQLITE3_INTEGER);
    $res = $stmt->execute();
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $out[] = $r; }
    return $out;
}

/**
 * The publishing gate (spec section 12). A post may go to the group only when
 * the transporter is approved, has a public username, the payment is settled
 * (or no payment was required), and it has NOT already been published.
 * Returns '' when publishing is allowed, otherwise the blocking reason.
 */
function hl_tr_publish_block_reason(SQLite3 $db, array $post, array $tr = null) {
    $tr = $tr ?: hl_tr_by_id($db, (int) $post['transporter_id']);
    if (!$tr) return 'Transporter record is missing.';
    if (($tr['verification_status'] ?? '') !== 'approved') return 'Transporter is not approved.';
    if (hl_tr_normalize_username($tr['username'] ?? '') === '') return 'Transporter has no public username yet.';
    // The duplicate guard: a stored Telegram message id means it is already out.
    if ((int) ($post['tg_message_id'] ?? 0) > 0) return 'This post was already published.';
    if (($post['post_status'] ?? '') === 'published') return 'This post was already published.';
    $pay = (string) ($post['payment_status'] ?? '');
    if (!in_array($pay, ['approved', 'free'], true)) return 'Payment is not approved yet.';
    return '';
}

/**
 * Mark a post published. Writes the Telegram message id and published_at in ONE
 * statement that only matches a not-yet-published row, so two concurrent
 * publishes can never both succeed.
 */
function hl_tr_mark_published(SQLite3 $db, $postId, $messageId) {
    $stmt = $db->prepare("UPDATE transporter_posts
                          SET post_status = 'published', tg_message_id = :m, published_at = datetime('now')
                          WHERE id = :id AND COALESCE(tg_message_id,0) = 0 AND post_status <> 'published'");
    $stmt->bindValue(':m', (int) $messageId, SQLITE3_INTEGER);
    $stmt->bindValue(':id', (int) $postId, SQLITE3_INTEGER);
    $stmt->execute();
    return $db->changes() > 0;
}

/** Count a free introductory post against the transporter's allowance. */
function hl_tr_consume_free_post(SQLite3 $db, $transporterId) {
    $stmt = $db->prepare('UPDATE transporters SET free_posts_used = free_posts_used + 1 WHERE id = :id');
    $stmt->bindValue(':id', (int) $transporterId, SQLITE3_INTEGER);
    return (bool) $stmt->execute();
}

/** How many posts this transporter has live (published), for the profile page. */
function hl_tr_published_count(SQLite3 $db, $transporterId) {
    $stmt = $db->prepare("SELECT COUNT(*) c FROM transporter_posts WHERE transporter_id = :t AND post_status = 'published'");
    $stmt->bindValue(':t', (int) $transporterId, SQLITE3_INTEGER);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return (int) ($r['c'] ?? 0);
}

/** The transporter's most recent published trip - shown as "current availability". */
function hl_tr_latest_post(SQLite3 $db, $transporterId) {
    $stmt = $db->prepare("SELECT * FROM transporter_posts
                          WHERE transporter_id = :t AND post_status = 'published'
                          ORDER BY id DESC LIMIT 1");
    $stmt->bindValue(':t', (int) $transporterId, SQLITE3_INTEGER);
    $r = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $r ?: null;
}

// ---------------------------------------------------------------------------
// Public projection + shared copy
// ---------------------------------------------------------------------------

/**
 * The ONLY shape that may be rendered on a public page. Everything private -
 * telegram_id, the internal id, admin notes, payment data, phone number - is
 * deliberately left out (spec section 6).
 */
function hl_tr_public(array $tr, SQLite3 $db = null) {
    $out = [
        'username'      => hl_tr_normalize_username($tr['username'] ?? ''),
        'display_name'  => (string) ($tr['full_name'] ?? ''),
        'photo_path'    => (string) ($tr['photo_path'] ?? ''),
        'origin'        => (string) ($tr['origin'] ?? ''),
        'destination'   => (string) ($tr['destination'] ?? ''),
        'contact_method'=> (string) ($tr['contact_method'] ?? ''),
        'availability'  => (string) ($tr['availability_note'] ?? ''),
        'rating'        => hl_tr_avg($tr),
        'rating_count'  => (int) ($tr['rating_count'] ?? 0),
        'verified'      => (($tr['verification_status'] ?? '') === 'approved'),
        'active'        => (($tr['verification_status'] ?? '') === 'approved'),
    ];
    $out['route'] = trim($out['origin']) !== '' || trim($out['destination']) !== ''
        ? trim($out['origin']) . "\xE2\x80\x89\xE2\x86\x92\xE2\x80\x89" . trim($out['destination'])
        : '';
    if ($db instanceof SQLite3 && ($tr['id'] ?? 0)) {
        $latest = hl_tr_latest_post($db, (int) $tr['id']);
        if ($latest) {
            $out['latest_trip'] = [
                'origin'      => (string) $latest['origin'],
                'destination' => (string) $latest['destination'],
                'travel_date' => (string) $latest['travel_date'],
                'space'       => (string) $latest['space'],
                'price'       => (string) $latest['price'],
            ];
        }
    }
    return $out;
}

/**
 * The platform disclaimer. ONE definition reused by the bot, the advertisement
 * body and the website, so the legal wording can never drift between them.
 */
function hl_tr_disclaimer($html = true) {
    $t = 'HabeshaList is an advertising and community platform. We do not receive, transport, '
       . 'store, insure or guarantee goods, and we do not guarantee transportation, delivery, '
       . 'payment, loss or damage. Any transportation payment is strictly between the customer '
       . 'and the transporter.';
    return $html ? '<i>' . $t . '</i>' : $t;
}

/** The Safety Notice shown from the menu and appended to profiles (spec 16). */
function hl_tr_safety_notice($html = true) {
    $lines = [
        'Before you agree to anything, confirm the route, travel date, destination, price, '
        . 'contact details and how the handover will happen.',
        'Keep your own records - messages, receipts and proof of any payment you make.',
        'Pay attention to who you are dealing with. Meet in a safe, public place when possible.',
        'HabeshaList is the advertising and community platform, not the carrier. We do not '
        . 'receive, transport, store, insure or guarantee goods.',
    ];
    if (!$html) return implode("\n\n", $lines);
    $out = '';
    foreach ($lines as $l) { $out .= "\xE2\x80\xA2 " . $l . "\n\n"; }
    return rtrim($out);
}
