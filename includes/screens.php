<?php
/**
 * screens.php - data + logic layer for the Digital Screen Advertising module.
 *
 * DESIGN
 * This module is fully SELF-CONTAINED and additive: it only CREATEs its own
 * three tables (screens / screen_pricing / screen_bookings) and never touches
 * any table the classifieds bot or the promotions engine already use. If the
 * module is disabled, nothing else changes. That is the "separate module"
 * guarantee - a bug in screens can never take down the live bot.
 *
 * It works against a SQLite3 handle that the CALLER passes in, so the same code
 * is reused by:
 *   - the admin panel  (passes hl_db())
 *   - the public player (passes its own read-only handle - it must NOT load the
 *     admin bootstrap, which forces HTTPS + admin headers)
 *   - later, the bot   (passes the bot's Database handle)
 *
 * FUTURE-PROOFING baked in now so we never have to migrate the schema:
 *   - each screen carries a stable, unguessable `slug` used in the public URL,
 *     so we can revoke/rotate a screen's public link without renumbering;
 *   - pricing lives in its OWN table keyed by screen (NULL screen_id = the
 *     global default), so day-part / seasonal / per-location rates are added
 *     later as DATA, not a schema change;
 *   - a booking stores its media as an ordered JSON PLAYLIST (even though the
 *     MVP uses a single file), so multi-slide rotation is data, not a migration;
 *   - `screens.last_seen` is written by the player heartbeat, the foundation for
 *     "screen online?" + proof-of-play reporting in phase 2.
 *
 * Money follows the rest of this codebase: prices are stored as plain dollars
 * (REAL) so hl_money() and the existing pricing UI keep working unchanged.
 */

const HL_SCREEN_ORIENTATIONS = ['landscape', 'portrait'];
const HL_SCREEN_IMAGE_EXT    = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
const HL_SCREEN_VIDEO_EXT    = ['mp4', 'webm', 'mov'];

/**
 * Create the module's tables if they do not exist. Safe to call on every
 * request - it is a no-op once the tables are present.
 */
function hl_screens_ensure_schema(SQLite3 $db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS screens (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            slug          TEXT UNIQUE NOT NULL,       -- unguessable token in the public URL
            name          TEXT NOT NULL,
            location      TEXT,
            orientation   TEXT DEFAULT 'portrait',    -- portrait | landscape (pilot is portrait-first)
            resolution    TEXT DEFAULT '1080x1920',   -- informational
            dwell_seconds INTEGER DEFAULT 10,         -- seconds each image shows in the loop
            status        TEXT DEFAULT 'active',      -- active | paused
            -- Interactive kiosk mode: the screen periodically switches from the
            -- passive ad loop to a touch-browsable view of the HabeshaList site.
            interactive         INTEGER DEFAULT 1,    -- 1 = enable website mode on this touchscreen
            website_url         TEXT DEFAULT '',      -- blank = site default (habeshalist.com)
            attract_seconds     INTEGER DEFAULT 180,  -- ad loop runs this long, then auto-opens the site
            idle_return_seconds INTEGER DEFAULT 60,   -- in site mode, return to ads after this much no-touch
            last_seen     TEXT,                       -- heartbeat from the player (proof-of-play seed)
            created_at    TEXT DEFAULT (datetime('now'))
        )");
    hl_screens_migrate($db);
    $db->exec("
        CREATE TABLE IF NOT EXISTS screen_pricing (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            screen_id  INTEGER,                       -- NULL = global default rate
            rate       REAL NOT NULL,                 -- price per unit, in dollars
            unit       TEXT DEFAULT 'day',            -- day | week (day-part/seasonal later)
            created_at TEXT DEFAULT (datetime('now'))
        )");
    $db->exec("
        CREATE TABLE IF NOT EXISTS screen_bookings (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            screen_id      INTEGER NOT NULL,
            telegram_id    INTEGER,                   -- booker (from the bot); NULL for admin/test
            business_name  TEXT,
            media          TEXT,                      -- JSON array: [{path,type,dwell}] (a playlist)
            start_date     TEXT NOT NULL,             -- YYYY-MM-DD inclusive (screen timezone)
            end_date       TEXT NOT NULL,             -- YYYY-MM-DD inclusive
            price          REAL DEFAULT 0,
            payment_status TEXT DEFAULT 'unpaid',     -- unpaid | paid
            payment_ref    TEXT,                      -- Stripe session id, etc.
            status         TEXT DEFAULT 'pending',    -- pending | approved | live | expired | rejected
            created_at     TEXT DEFAULT (datetime('now'))
        )");
    // Helpful indexes for the two hot queries (availability + what-plays-now).
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sb_screen_dates ON screen_bookings(screen_id, start_date, end_date)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sb_status ON screen_bookings(status)");
}

/**
 * Add any columns introduced after a screen table already existed. SQLite has no
 * "ADD COLUMN IF NOT EXISTS", so we diff against PRAGMA table_info and ALTER the
 * gaps. Safe/idempotent - a no-op once every column is present.
 */
function hl_screens_migrate(SQLite3 $db) {
    $have = [];
    $res = $db->query("PRAGMA table_info(screens)");
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $have[$r['name']] = true; }
    $add = [
        'interactive'         => "INTEGER DEFAULT 1",
        'website_url'         => "TEXT DEFAULT ''",
        'attract_seconds'     => "INTEGER DEFAULT 180",
        'idle_return_seconds' => "INTEGER DEFAULT 60",
    ];
    foreach ($add as $col => $decl) {
        if (empty($have[$col])) $db->exec("ALTER TABLE screens ADD COLUMN {$col} {$decl}");
    }
}

/** An unguessable URL-safe token for a screen's public player link. */
function hl_screen_new_slug() {
    return bin2hex(random_bytes(6)); // 12 hex chars, ~48 bits
}

// ---------------------------------------------------------------------------
// Reads
// ---------------------------------------------------------------------------

function hl_screens_all(SQLite3 $db) {
    $rows = [];
    $res = $db->query("SELECT * FROM screens ORDER BY created_at DESC, id DESC");
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) { $rows[] = $r; }
    return $rows;
}

function hl_screen_by_id(SQLite3 $db, $id) {
    $st = $db->prepare("SELECT * FROM screens WHERE id = :id");
    $st->bindValue(':id', (int) $id, SQLITE3_INTEGER);
    $res = $st->execute();
    return $res ? ($res->fetchArray(SQLITE3_ASSOC) ?: null) : null;
}

function hl_screen_by_slug(SQLite3 $db, $slug) {
    $st = $db->prepare("SELECT * FROM screens WHERE slug = :s");
    $st->bindValue(':s', (string) $slug, SQLITE3_TEXT);
    $res = $st->execute();
    return $res ? ($res->fetchArray(SQLITE3_ASSOC) ?: null) : null;
}

/**
 * Effective rate for a screen: its own price row if set, otherwise the global
 * default row, otherwise null (not priced yet). Returns ['rate','unit'] or null.
 */
function hl_screen_rate(SQLite3 $db, $screenId) {
    $st = $db->prepare("SELECT rate, unit FROM screen_pricing WHERE screen_id = :id ORDER BY id DESC LIMIT 1");
    $st->bindValue(':id', (int) $screenId, SQLITE3_INTEGER);
    $res = $st->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
    if ($row) return ['rate' => (float) $row['rate'], 'unit' => $row['unit']];

    $res = $db->query("SELECT rate, unit FROM screen_pricing WHERE screen_id IS NULL ORDER BY id DESC LIMIT 1");
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
    return $row ? ['rate' => (float) $row['rate'], 'unit' => $row['unit']] : null;
}

/**
 * The ordered media playlist that should be on a screen RIGHT NOW: every
 * approved/live booking whose date range covers $today, flattened into one
 * ordered list of items. Each item is ['path','type','dwell'].
 *
 * Only approved/live bookings are ever returned - a pending or rejected ad can
 * never reach a public screen. This is the single source of truth the player
 * pulls; keeping it here (not in the player) means the bot, admin preview and
 * the TV all agree on what is showing.
 */
function hl_screen_playlist(SQLite3 $db, $screenId, $today, $defaultDwell = 10) {
    $st = $db->prepare("
        SELECT media FROM screen_bookings
        WHERE screen_id = :id
          AND status IN ('approved','live')
          AND payment_status = 'paid'
          AND start_date <= :today AND end_date >= :today
        ORDER BY id ASC");
    $st->bindValue(':id', (int) $screenId, SQLITE3_INTEGER);
    $st->bindValue(':today', (string) $today, SQLITE3_TEXT);
    $res = $st->execute();

    $items = [];
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
        $media = json_decode((string) $r['media'], true);
        if (!is_array($media)) continue;
        foreach ($media as $m) {
            if (empty($m['path'])) continue;
            $items[] = [
                'path'  => (string) $m['path'],
                'type'  => in_array(($m['type'] ?? ''), ['image', 'video'], true) ? $m['type'] : hl_screen_media_type($m['path']),
                'dwell' => (int) ($m['dwell'] ?? $defaultDwell) ?: $defaultDwell,
            ];
        }
    }
    return $items;
}

/** Guess media type from a filename extension. */
function hl_screen_media_type($path) {
    $ext = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
    if (in_array($ext, HL_SCREEN_VIDEO_EXT, true)) return 'video';
    return 'image';
}

/**
 * Is a screen free for the whole [start,end] range? A slot is taken by any
 * pending/approved/live booking that overlaps. (MVP = one advertiser per screen
 * at a time; multiple concurrent slots per screen is a phase-2 pricing choice.)
 */
function hl_screen_is_available(SQLite3 $db, $screenId, $start, $end, $ignoreBookingId = 0) {
    $st = $db->prepare("
        SELECT COUNT(*) AS n FROM screen_bookings
        WHERE screen_id = :id
          AND id <> :ignore
          AND status IN ('pending','approved','live')
          AND start_date <= :end AND end_date >= :start");
    $st->bindValue(':id', (int) $screenId, SQLITE3_INTEGER);
    $st->bindValue(':ignore', (int) $ignoreBookingId, SQLITE3_INTEGER);
    $st->bindValue(':start', (string) $start, SQLITE3_TEXT);
    $st->bindValue(':end', (string) $end, SQLITE3_TEXT);
    $res = $st->execute();
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : ['n' => 1];
    return ((int) ($row['n'] ?? 1)) === 0;
}

// ---------------------------------------------------------------------------
// Writes
// ---------------------------------------------------------------------------

function hl_screen_create(SQLite3 $db, array $d) {
    $st = $db->prepare("
        INSERT INTO screens (slug, name, location, orientation, resolution, dwell_seconds, status,
                             interactive, website_url, attract_seconds, idle_return_seconds)
        VALUES (:slug, :name, :loc, :ori, :res, :dwell, 'active',
                :inter, :wurl, :attract, :idle)");
    $st->bindValue(':slug', hl_screen_new_slug(), SQLITE3_TEXT);
    $st->bindValue(':name', trim($d['name'] ?? ''), SQLITE3_TEXT);
    $st->bindValue(':loc', trim($d['location'] ?? ''), SQLITE3_TEXT);
    $ori = in_array($d['orientation'] ?? '', HL_SCREEN_ORIENTATIONS, true) ? $d['orientation'] : 'portrait';
    $st->bindValue(':ori', $ori, SQLITE3_TEXT);
    $st->bindValue(':res', trim($d['resolution'] ?? '1080x1920'), SQLITE3_TEXT);
    $st->bindValue(':dwell', max(3, (int) ($d['dwell_seconds'] ?? 10)), SQLITE3_INTEGER);
    $st->bindValue(':inter', !empty($d['interactive']) ? 1 : 0, SQLITE3_INTEGER);
    $st->bindValue(':wurl', trim($d['website_url'] ?? ''), SQLITE3_TEXT);
    $st->bindValue(':attract', max(15, (int) ($d['attract_seconds'] ?? 180)), SQLITE3_INTEGER);
    $st->bindValue(':idle', max(10, (int) ($d['idle_return_seconds'] ?? 60)), SQLITE3_INTEGER);
    $st->execute();
    return $db->lastInsertRowID();
}

function hl_screen_update(SQLite3 $db, $id, array $d) {
    $st = $db->prepare("
        UPDATE screens
        SET name = :name, location = :loc, orientation = :ori,
            resolution = :res, dwell_seconds = :dwell, status = :status,
            interactive = :inter, website_url = :wurl,
            attract_seconds = :attract, idle_return_seconds = :idle
        WHERE id = :id");
    $st->bindValue(':name', trim($d['name'] ?? ''), SQLITE3_TEXT);
    $st->bindValue(':loc', trim($d['location'] ?? ''), SQLITE3_TEXT);
    $ori = in_array($d['orientation'] ?? '', HL_SCREEN_ORIENTATIONS, true) ? $d['orientation'] : 'portrait';
    $st->bindValue(':ori', $ori, SQLITE3_TEXT);
    $st->bindValue(':res', trim($d['resolution'] ?? '1080x1920'), SQLITE3_TEXT);
    $st->bindValue(':dwell', max(3, (int) ($d['dwell_seconds'] ?? 10)), SQLITE3_INTEGER);
    $status = in_array($d['status'] ?? '', ['active', 'paused'], true) ? $d['status'] : 'active';
    $st->bindValue(':status', $status, SQLITE3_TEXT);
    $st->bindValue(':inter', !empty($d['interactive']) ? 1 : 0, SQLITE3_INTEGER);
    $st->bindValue(':wurl', trim($d['website_url'] ?? ''), SQLITE3_TEXT);
    $st->bindValue(':attract', max(15, (int) ($d['attract_seconds'] ?? 180)), SQLITE3_INTEGER);
    $st->bindValue(':idle', max(10, (int) ($d['idle_return_seconds'] ?? 60)), SQLITE3_INTEGER);
    $st->bindValue(':id', (int) $id, SQLITE3_INTEGER);
    $st->execute();
}

/** Set (replace) a screen's own price row, or the global default when screenId is null. */
function hl_screen_set_rate(SQLite3 $db, $screenId, $rate, $unit = 'day') {
    $unit = in_array($unit, ['day', 'week'], true) ? $unit : 'day';
    if ($screenId === null) {
        $db->exec("DELETE FROM screen_pricing WHERE screen_id IS NULL");
        $st = $db->prepare("INSERT INTO screen_pricing (screen_id, rate, unit) VALUES (NULL, :r, :u)");
    } else {
        $st = $db->prepare("DELETE FROM screen_pricing WHERE screen_id = :id");
        $st->bindValue(':id', (int) $screenId, SQLITE3_INTEGER);
        $st->execute();
        $st = $db->prepare("INSERT INTO screen_pricing (screen_id, rate, unit) VALUES (:id, :r, :u)");
        $st->bindValue(':id', (int) $screenId, SQLITE3_INTEGER);
    }
    $st->bindValue(':r', (float) $rate, SQLITE3_FLOAT);
    $st->bindValue(':u', $unit, SQLITE3_TEXT);
    $st->execute();
}

/** Record that a player pulled this screen's playlist (proof-of-play foundation). */
function hl_screen_touch(SQLite3 $db, $screenId, $nowUtc) {
    $st = $db->prepare("UPDATE screens SET last_seen = :now WHERE id = :id");
    $st->bindValue(':now', (string) $nowUtc, SQLITE3_TEXT);
    $st->bindValue(':id', (int) $screenId, SQLITE3_INTEGER);
    $st->execute();
}

// ---------------------------------------------------------------------------
// House ads
//
// Milestone 1 has no advertiser-facing booking flow yet (that is Milestone 2:
// pick screen -> dates -> upload flyer -> pay). But the owner still needs to put
// their OWN content on a screen from day one - promotions for the venue, a menu,
// a welcome slide - and they need to SEE the player actually playing something.
//
// A "house ad" is exactly that: a booking the admin creates directly. It is
// stored in the same screen_bookings table as a paid + approved booking so it
// flows through the identical playlist path a real paid ad will - no special
// case in the player. When the Milestone 2 booking flow lands, paid advertiser
// bookings simply join these in the same list.
// ---------------------------------------------------------------------------

/**
 * Create a booking directly from the admin (a "house ad"). $media is an ordered
 * array of ['path','type','dwell'] items. It is stored paid + approved so it is
 * live immediately for the given date range. Returns the new booking id.
 */
function hl_screen_add_house_ad(SQLite3 $db, $screenId, array $media, $start, $end, $label = 'House ad') {
    $clean = [];
    foreach ($media as $m) {
        if (empty($m['path'])) continue;
        $clean[] = [
            'path'  => (string) $m['path'],
            'type'  => in_array(($m['type'] ?? ''), ['image', 'video'], true) ? $m['type'] : hl_screen_media_type($m['path']),
            'dwell' => (int) ($m['dwell'] ?? 10) ?: 10,
        ];
    }
    if (!$clean) return 0;
    $st = $db->prepare("
        INSERT INTO screen_bookings
            (screen_id, telegram_id, business_name, media, start_date, end_date,
             price, payment_status, payment_ref, status)
        VALUES
            (:sid, NULL, :label, :media, :start, :end,
             0, 'paid', 'house', 'approved')");
    $st->bindValue(':sid', (int) $screenId, SQLITE3_INTEGER);
    $st->bindValue(':label', (string) $label, SQLITE3_TEXT);
    $st->bindValue(':media', json_encode(array_values($clean)), SQLITE3_TEXT);
    $st->bindValue(':start', (string) $start, SQLITE3_TEXT);
    $st->bindValue(':end', (string) $end, SQLITE3_TEXT);
    $st->execute();
    return $db->lastInsertRowID();
}

/** All bookings on a screen, newest first (admin view of what is / was scheduled). */
function hl_screen_bookings(SQLite3 $db, $screenId) {
    $rows = [];
    $st = $db->prepare("SELECT * FROM screen_bookings WHERE screen_id = :id ORDER BY id DESC");
    $st->bindValue(':id', (int) $screenId, SQLITE3_INTEGER);
    $res = $st->execute();
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
        $r['media_items'] = json_decode((string) $r['media'], true) ?: [];
        $rows[] = $r;
    }
    return $rows;
}

function hl_screen_booking_by_id(SQLite3 $db, $bookingId) {
    $st = $db->prepare("SELECT * FROM screen_bookings WHERE id = :id");
    $st->bindValue(':id', (int) $bookingId, SQLITE3_INTEGER);
    $res = $st->execute();
    return $res ? ($res->fetchArray(SQLITE3_ASSOC) ?: null) : null;
}

/** Delete a booking. Returns its media item list so the caller can unlink files. */
function hl_screen_delete_booking(SQLite3 $db, $bookingId) {
    $b = hl_screen_booking_by_id($db, $bookingId);
    if (!$b) return [];
    $media = json_decode((string) $b['media'], true) ?: [];
    $st = $db->prepare("DELETE FROM screen_bookings WHERE id = :id");
    $st->bindValue(':id', (int) $bookingId, SQLITE3_INTEGER);
    $st->execute();
    return $media;
}

// ---------------------------------------------------------------------------
// Advertiser bookings (Milestone 2)
//
// A customer books a screen from inside the Telegram bot: pick screen -> dates
// -> upload flyer -> pay. That creates a booking with status 'pending' (and a
// payment_status reflecting how they paid). An admin then approves it, which
// flips status to 'approved' + payment_status 'paid' - the exact state the
// player's playlist requires - so it goes live on its start date automatically.
// Uses the SAME screen_bookings table and playlist path as house ads.
// ---------------------------------------------------------------------------

/**
 * Create an advertiser booking. $opts: screen_id, telegram_id, business_name,
 * media (array of ['path','type','dwell']), start_date, end_date, price,
 * payment_status ('paid'|'awaiting_verification'|'unpaid'), payment_ref,
 * status (default 'pending'). Returns the new booking id (0 if no media).
 */
function hl_screen_book(SQLite3 $db, array $opts) {
    $clean = [];
    foreach (($opts['media'] ?? []) as $m) {
        if (empty($m['path'])) continue;
        $clean[] = [
            'path'  => (string) $m['path'],
            'type'  => in_array(($m['type'] ?? ''), ['image', 'video'], true) ? $m['type'] : hl_screen_media_type($m['path']),
            'dwell' => (int) ($m['dwell'] ?? 10) ?: 10,
        ];
    }
    if (!$clean) return 0;
    $status  = in_array($opts['status'] ?? '', ['pending', 'approved', 'live', 'expired', 'rejected'], true)
        ? $opts['status'] : 'pending';
    $payStat = in_array($opts['payment_status'] ?? '', ['unpaid', 'awaiting_verification', 'paid'], true)
        ? $opts['payment_status'] : 'unpaid';
    $st = $db->prepare("
        INSERT INTO screen_bookings
            (screen_id, telegram_id, business_name, media, start_date, end_date,
             price, payment_status, payment_ref, status)
        VALUES
            (:sid, :tid, :bname, :media, :start, :end,
             :price, :pstat, :pref, :status)");
    $st->bindValue(':sid',   (int) ($opts['screen_id'] ?? 0), SQLITE3_INTEGER);
    $st->bindValue(':tid',   isset($opts['telegram_id']) ? (int) $opts['telegram_id'] : null,
                             isset($opts['telegram_id']) ? SQLITE3_INTEGER : SQLITE3_NULL);
    $st->bindValue(':bname', trim($opts['business_name'] ?? ''), SQLITE3_TEXT);
    $st->bindValue(':media', json_encode(array_values($clean)), SQLITE3_TEXT);
    $st->bindValue(':start', (string) ($opts['start_date'] ?? ''), SQLITE3_TEXT);
    $st->bindValue(':end',   (string) ($opts['end_date'] ?? ''), SQLITE3_TEXT);
    $st->bindValue(':price', (float) ($opts['price'] ?? 0), SQLITE3_FLOAT);
    $st->bindValue(':pstat', $payStat, SQLITE3_TEXT);
    $st->bindValue(':pref',  (string) ($opts['payment_ref'] ?? ''), SQLITE3_TEXT);
    $st->bindValue(':status', $status, SQLITE3_TEXT);
    $st->execute();
    return $db->lastInsertRowID();
}

/**
 * Move a booking to a new status (and optionally a new payment_status). Used by
 * both the Telegram approve/reject buttons and the web admin approval queue.
 */
function hl_screen_set_booking_status(SQLite3 $db, $bookingId, $status, $paymentStatus = null) {
    $status = in_array($status, ['pending', 'approved', 'live', 'expired', 'rejected'], true) ? $status : 'pending';
    if ($paymentStatus !== null) {
        $st = $db->prepare("UPDATE screen_bookings SET status = :s, payment_status = :p WHERE id = :id");
        $st->bindValue(':p', (string) $paymentStatus, SQLITE3_TEXT);
    } else {
        $st = $db->prepare("UPDATE screen_bookings SET status = :s WHERE id = :id");
    }
    $st->bindValue(':s', $status, SQLITE3_TEXT);
    $st->bindValue(':id', (int) $bookingId, SQLITE3_INTEGER);
    $st->execute();
    return $db->changes() > 0;
}

/** Bookings awaiting admin approval (status 'pending'), oldest first, with the screen name joined. */
function hl_screen_pending_bookings(SQLite3 $db) {
    $rows = [];
    $res = $db->query("
        SELECT b.*, s.name AS screen_name, s.location AS screen_location
        FROM screen_bookings b
        LEFT JOIN screens s ON s.id = b.screen_id
        WHERE b.status = 'pending'
        ORDER BY b.id ASC");
    while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
        $r['media_items'] = json_decode((string) $r['media'], true) ?: [];
        $rows[] = $r;
    }
    return $rows;
}

/** Count of bookings awaiting approval (for an admin badge). */
function hl_screen_pending_count(SQLite3 $db) {
    $res = $db->query("SELECT COUNT(*) AS n FROM screen_bookings WHERE status = 'pending'");
    $row = $res ? $res->fetchArray(SQLITE3_ASSOC) : ['n' => 0];
    return (int) ($row['n'] ?? 0);
}

/**
 * Price for booking a screen for $days days at its effective rate. Day-rate =
 * rate * days; week-rate = rate * whole-or-part weeks. Returns a float (dollars),
 * or null if the screen has no price set yet.
 */
function hl_screen_price_for(SQLite3 $db, $screenId, $days) {
    $rate = hl_screen_rate($db, $screenId);
    if (!$rate) return null;
    $days = max(1, (int) $days);
    if (($rate['unit'] ?? 'day') === 'week') {
        return (float) $rate['rate'] * (int) ceil($days / 7);
    }
    return (float) $rate['rate'] * $days;
}
