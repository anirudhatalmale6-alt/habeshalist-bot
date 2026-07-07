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
}
