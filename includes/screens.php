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
            orientation   TEXT DEFAULT 'landscape',   -- landscape | portrait
            resolution    TEXT DEFAULT '1920x1080',   -- informational
            dwell_seconds INTEGER DEFAULT 10,         -- seconds each image shows in the loop
            status        TEXT DEFAULT 'active',      -- active | paused
            last_seen     TEXT,                       -- heartbeat from the player (proof-of-play seed)
            created_at    TEXT DEFAULT (datetime('now'))
        )");
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
        INSERT INTO screens (slug, name, location, orientation, resolution, dwell_seconds, status)
        VALUES (:slug, :name, :loc, :ori, :res, :dwell, 'active')");
    $st->bindValue(':slug', hl_screen_new_slug(), SQLITE3_TEXT);
    $st->bindValue(':name', trim($d['name'] ?? ''), SQLITE3_TEXT);
    $st->bindValue(':loc', trim($d['location'] ?? ''), SQLITE3_TEXT);
    $ori = in_array($d['orientation'] ?? '', HL_SCREEN_ORIENTATIONS, true) ? $d['orientation'] : 'landscape';
    $st->bindValue(':ori', $ori, SQLITE3_TEXT);
    $st->bindValue(':res', trim($d['resolution'] ?? '1920x1080'), SQLITE3_TEXT);
    $st->bindValue(':dwell', max(3, (int) ($d['dwell_seconds'] ?? 10)), SQLITE3_INTEGER);
    $st->execute();
    return $db->lastInsertRowID();
}

function hl_screen_update(SQLite3 $db, $id, array $d) {
    $st = $db->prepare("
        UPDATE screens
        SET name = :name, location = :loc, orientation = :ori,
            resolution = :res, dwell_seconds = :dwell, status = :status
        WHERE id = :id");
    $st->bindValue(':name', trim($d['name'] ?? ''), SQLITE3_TEXT);
    $st->bindValue(':loc', trim($d['location'] ?? ''), SQLITE3_TEXT);
    $ori = in_array($d['orientation'] ?? '', HL_SCREEN_ORIENTATIONS, true) ? $d['orientation'] : 'landscape';
    $st->bindValue(':ori', $ori, SQLITE3_TEXT);
    $st->bindValue(':res', trim($d['resolution'] ?? '1920x1080'), SQLITE3_TEXT);
    $st->bindValue(':dwell', max(3, (int) ($d['dwell_seconds'] ?? 10)), SQLITE3_INTEGER);
    $status = in_array($d['status'] ?? '', ['active', 'paused'], true) ? $d['status'] : 'active';
    $st->bindValue(':status', $status, SQLITE3_TEXT);
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
