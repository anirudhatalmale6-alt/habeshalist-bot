<?php

class Database {
    private $db;

    public function __construct($dbPath) {
        $dir = dirname($dbPath);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $this->db = new SQLite3($dbPath);
        $this->db->busyTimeout(5000);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->init();
    }

    private function init() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id INTEGER UNIQUE NOT NULL,
                name TEXT,
                phone TEXT,
                email TEXT,
                registered_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS ads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id INTEGER NOT NULL,
                category TEXT,
                subcategory TEXT,
                title TEXT,
                description TEXT,
                price TEXT,
                location TEXT,
                photos TEXT,
                status TEXT DEFAULT 'draft',
                osclass_id INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS user_states (
                telegram_id INTEGER PRIMARY KEY,
                state TEXT DEFAULT 'idle',
                data TEXT DEFAULT '{}',
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Admin-editable key/value settings (package prices, payment handles, etc.)
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT
            )
        ");

        // Paid business promotions (Promote My Business)
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS promotions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                telegram_id INTEGER NOT NULL,
                package_key TEXT,
                price REAL,
                payment_method TEXT,
                payment_status TEXT DEFAULT 'unpaid',
                payment_proof TEXT,
                receipt TEXT,
                business_name TEXT,
                business_category TEXT,
                description TEXT,
                phone TEXT,
                website TEXT,
                social TEXT,
                address TEXT,
                hours TEXT,
                logo TEXT,
                images TEXT,
                cta TEXT,
                posts_total INTEGER DEFAULT 0,
                posts_used INTEGER DEFAULT 0,
                start_date TEXT,
                end_date TEXT,
                schedule TEXT,
                status TEXT DEFAULT 'draft',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Migration: link bot users to their OSClass website account
        $cols = [];
        $res = $this->db->query("PRAGMA table_info(users)");
        while ($res && ($r = $res->fetchArray(SQLITE3_ASSOC))) {
            $cols[] = $r['name'];
        }
        if (!in_array('osclass_user_id', $cols)) {
            $this->db->exec("ALTER TABLE users ADD COLUMN osclass_user_id INTEGER");
        }
    }

    public function getUser($telegramId) {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE telegram_id = :tid');
        $stmt->bindValue(':tid', $telegramId, SQLITE3_INTEGER);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }

    public function createUser($telegramId, $name, $phone, $email) {
        $existing = $this->getUser($telegramId);
        if ($existing) {
            $stmt = $this->db->prepare('UPDATE users SET name = :name, phone = :phone, email = :email WHERE telegram_id = :tid');
        } else {
            $stmt = $this->db->prepare('INSERT INTO users (telegram_id, name, phone, email) VALUES (:tid, :name, :phone, :email)');
        }
        $stmt->bindValue(':tid', $telegramId, SQLITE3_INTEGER);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':phone', $phone, SQLITE3_TEXT);
        $stmt->bindValue(':email', $email, SQLITE3_TEXT);
        return $stmt->execute();
    }

    public function setOsclassUserId($telegramId, $osclassUserId) {
        $stmt = $this->db->prepare('UPDATE users SET osclass_user_id = :oid WHERE telegram_id = :tid');
        $stmt->bindValue(':oid', $osclassUserId, SQLITE3_INTEGER);
        $stmt->bindValue(':tid', $telegramId, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function deleteUser($telegramId) {
        $stmt = $this->db->prepare('DELETE FROM users WHERE telegram_id = :tid');
        $stmt->bindValue(':tid', $telegramId, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    public function getState($telegramId) {
        $stmt = $this->db->prepare('SELECT state, data FROM user_states WHERE telegram_id = :tid');
        $stmt->bindValue(':tid', $telegramId, SQLITE3_INTEGER);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($row) {
            return ['state' => $row['state'], 'data' => json_decode($row['data'], true) ?: []];
        }
        return ['state' => 'idle', 'data' => []];
    }

    public function setState($telegramId, $state, $data = []) {
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO user_states (telegram_id, state, data, updated_at) VALUES (:tid, :state, :data, CURRENT_TIMESTAMP)');
        $stmt->bindValue(':tid', $telegramId, SQLITE3_INTEGER);
        $stmt->bindValue(':state', $state, SQLITE3_TEXT);
        $stmt->bindValue(':data', json_encode($data), SQLITE3_TEXT);
        return $stmt->execute();
    }

    public function createAd($telegramId, $adData) {
        $stmt = $this->db->prepare('INSERT INTO ads (telegram_id, category, subcategory, title, description, price, location, photos, status) VALUES (:tid, :cat, :subcat, :title, :desc, :price, :loc, :photos, :status)');
        $stmt->bindValue(':tid', $telegramId, SQLITE3_INTEGER);
        $stmt->bindValue(':cat', $adData['category'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':subcat', $adData['subcategory'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':title', $adData['title'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':desc', $adData['description'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':price', $adData['price'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':loc', $adData['location_name'] ?? $adData['location'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':photos', json_encode($adData['photos'] ?? []), SQLITE3_TEXT);
        $stmt->bindValue(':status', 'pending', SQLITE3_TEXT);
        $stmt->execute();
        return $this->db->lastInsertRowID();
    }

    public function getAd($adId) {
        $stmt = $this->db->prepare('SELECT * FROM ads WHERE id = :id');
        $stmt->bindValue(':id', $adId, SQLITE3_INTEGER);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }

    public function getUserAds($telegramId) {
        $stmt = $this->db->prepare('SELECT * FROM ads WHERE telegram_id = :tid ORDER BY created_at DESC LIMIT 10');
        $stmt->bindValue(':tid', $telegramId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $ads = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $ads[] = $row;
        }
        return $ads;
    }

    public function updateAdStatus($adId, $status, $osclassId = null) {
        if ($osclassId) {
            $stmt = $this->db->prepare('UPDATE ads SET status = :status, osclass_id = :oid WHERE id = :id');
            $stmt->bindValue(':oid', $osclassId, SQLITE3_INTEGER);
        } else {
            $stmt = $this->db->prepare('UPDATE ads SET status = :status WHERE id = :id');
        }
        $stmt->bindValue(':status', $status, SQLITE3_TEXT);
        $stmt->bindValue(':id', $adId, SQLITE3_INTEGER);
        return $stmt->execute();
    }

    // ---- Settings (admin-editable key/value) ----

    public function getSetting($key, $default = null) {
        $stmt = $this->db->prepare('SELECT value FROM settings WHERE key = :k');
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $row ? $row['value'] : $default;
    }

    public function setSetting($key, $value) {
        $stmt = $this->db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (:k, :v)');
        $stmt->bindValue(':k', $key, SQLITE3_TEXT);
        $stmt->bindValue(':v', $value, SQLITE3_TEXT);
        return $stmt->execute();
    }

    // ---- Promotions (Promote My Business) ----

    public function createPromotion($telegramId, $data) {
        $stmt = $this->db->prepare('INSERT INTO promotions
            (telegram_id, package_key, price, payment_method, payment_status, payment_proof, receipt,
             business_name, business_category, description, phone, website, social, address, hours,
             logo, images, cta, posts_total, posts_used, start_date, end_date, schedule, status)
            VALUES
            (:tid, :pkg, :price, :pm, :ps, :proof, :receipt,
             :bname, :bcat, :desc, :phone, :website, :social, :address, :hours,
             :logo, :images, :cta, :ptotal, :pused, :sdate, :edate, :sched, :status)');
        $stmt->bindValue(':tid', $telegramId, SQLITE3_INTEGER);
        $stmt->bindValue(':pkg', $data['package_key'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':price', $data['price'] ?? 0, SQLITE3_FLOAT);
        $stmt->bindValue(':pm', $data['payment_method'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':ps', $data['payment_status'] ?? 'unpaid', SQLITE3_TEXT);
        $stmt->bindValue(':proof', $data['payment_proof'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':receipt', $data['receipt'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':bname', $data['business_name'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':bcat', $data['business_category'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':desc', $data['description'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':phone', $data['phone'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':website', $data['website'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':social', $data['social'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':address', $data['address'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':hours', $data['hours'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':logo', $data['logo'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':images', json_encode($data['images'] ?? []), SQLITE3_TEXT);
        $stmt->bindValue(':cta', $data['cta'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':ptotal', $data['posts_total'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':pused', $data['posts_used'] ?? 0, SQLITE3_INTEGER);
        $stmt->bindValue(':sdate', $data['start_date'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':edate', $data['end_date'] ?? '', SQLITE3_TEXT);
        $stmt->bindValue(':sched', json_encode($data['schedule'] ?? []), SQLITE3_TEXT);
        $stmt->bindValue(':status', $data['status'] ?? 'draft', SQLITE3_TEXT);
        $stmt->execute();
        return $this->db->lastInsertRowID();
    }

    public function getPromotion($id) {
        $stmt = $this->db->prepare('SELECT * FROM promotions WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        return $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    }

    public function getUserPromotions($telegramId) {
        $stmt = $this->db->prepare('SELECT * FROM promotions WHERE telegram_id = :tid ORDER BY created_at DESC LIMIT 20');
        $stmt->bindValue(':tid', $telegramId, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function updatePromotion($id, $fields) {
        $allowed = [
            'package_key', 'price', 'payment_method', 'payment_status', 'payment_proof', 'receipt',
            'business_name', 'business_category', 'description', 'phone', 'website', 'social',
            'address', 'hours', 'logo', 'cta', 'posts_total', 'posts_used',
            'start_date', 'end_date', 'status',
        ];
        $sets = [];
        $binds = [];
        foreach ($fields as $k => $v) {
            if ($k === 'images' || $k === 'schedule') {
                $sets[] = "$k = :$k";
                $binds[":$k"] = json_encode($v);
                continue;
            }
            if (!in_array($k, $allowed, true)) continue;
            $sets[] = "$k = :$k";
            $binds[":$k"] = $v;
        }
        if (empty($sets)) return false;
        $sql = 'UPDATE promotions SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        foreach ($binds as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        return $stmt->execute();
    }
}
