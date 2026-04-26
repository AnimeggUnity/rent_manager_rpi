<?php
require_once __DIR__ . '/../config.php';

class DB {
    private static $pdo;

    private static function hasColumn(PDO $pdo, string $table, string $column): bool {
        $info = $pdo->query("PRAGMA table_info($table)")->fetchAll();
        foreach ($info as $col) {
            if ($col['name'] === $column) {
                return true;
            }
        }
        return false;
    }

    public static function generateShareCode(PDO $pdo): string {
        do {
            $code = bin2hex(random_bytes(8));
            $stmt = $pdo->prepare("SELECT 1 FROM tenants WHERE share_code = ? LIMIT 1");
            $stmt->execute([$code]);
            $exists = $stmt->fetchColumn();
        } while ($exists);

        return $code;
    }

    public static function resetConnection() {
        self::$pdo = null;
    }

    public static function connect() {
        if (self::$pdo === null) {
            if (!is_dir(sc_DB_DIR)) {
                mkdir(sc_DB_DIR, 0755, true);
            }

            try {
                self::$pdo = new PDO('sqlite:' . sc_DB_FILE);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                die("Database connection failed: " . $e->getMessage());
            }
        }
        return self::$pdo;
    }

    public static function nukeAndReinit() {
        $pdo = self::connect();
        // Drop tables in correct order of dependency
        $tables = [
            'unit_asset_photos',
            'tenant_documents',
            'asset_maintenance',
            'unit_assets',
            'utility_bills',
            'ledger_attachments',
            'ledger',
            'electricity_readings',
            'expense_categories',
            'tenants',
            'units'
        ];
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS $table");
        }
        self::initSchema();
        return true;
    }

    public static function initSchema(PDO $pdo = null) {
        if ($pdo === null) {
            $pdo = self::connect();
        }

        $commands = [
            // 1. Units
            "CREATE TABLE IF NOT EXISTS units (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                base_rent INTEGER DEFAULT 0,
                description TEXT,
                is_active BOOLEAN DEFAULT 1
            )",
            
            // 2. Tenants
            "CREATE TABLE IF NOT EXISTS tenants (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                contact_info TEXT,
                unit_id INTEGER,
                contract_sign_date DATE,
                contract_start DATE,
                contract_end DATE,
                billing_cycle_day INTEGER DEFAULT 1,
                security_deposit INTEGER DEFAULT 0,
                balance INTEGER DEFAULT 0,
                is_active BOOLEAN DEFAULT 1,
                share_code TEXT,
                FOREIGN KEY(unit_id) REFERENCES units(id)
            )",

            // 3. Electricity Meters
            "CREATE TABLE IF NOT EXISTS electricity_readings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                unit_id INTEGER NOT NULL,
                record_date DATE NOT NULL,
                reading_value REAL NOT NULL, 
                diff_value REAL DEFAULT 0,
                record_type TEXT DEFAULT 'daily', 
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(unit_id) REFERENCES units(id),
                UNIQUE(unit_id, record_date, record_type)
            )",

            // 4. Ledger / Transactions
            "CREATE TABLE IF NOT EXISTS ledger (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                trans_date DATE NOT NULL,
                type TEXT NOT NULL,
                category TEXT NOT NULL,
                amount INTEGER NOT NULL,
                description TEXT,
                tenant_id INTEGER,
                unit_id INTEGER,
                ref_type TEXT,
                ref_id INTEGER,
                is_paid BOOLEAN DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            // 4a. Utility Bills
            "CREATE TABLE IF NOT EXISTS utility_bills (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                building_id INTEGER,
                period_start DATE NOT NULL,
                period_end DATE NOT NULL,
                kwh REAL NOT NULL,
                amount INTEGER NOT NULL,
                bill_date DATE NOT NULL,
                note TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            // 4b. Ledger Attachments
            "CREATE TABLE IF NOT EXISTS ledger_attachments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ledger_id INTEGER NOT NULL,
                file_path TEXT NOT NULL,
                file_name TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(ledger_id) REFERENCES ledger(id) ON DELETE CASCADE
            )",

            // 5. Unit Assets
            "CREATE TABLE IF NOT EXISTS unit_assets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                unit_id INTEGER NOT NULL,
                item_name TEXT NOT NULL,
                purchase_date DATE,
                warranty_date DATE,
                purchase_cost INTEGER DEFAULT 0,
                status TEXT DEFAULT '正常',
                FOREIGN KEY(unit_id) REFERENCES units(id)
            )",

            // 6. Asset Maintenance
            "CREATE TABLE IF NOT EXISTS asset_maintenance (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                asset_id INTEGER NOT NULL,
                repair_date DATE NOT NULL,
                repair_cost INTEGER DEFAULT 0,
                repair_person TEXT,
                details TEXT,
                FOREIGN KEY(asset_id) REFERENCES unit_assets(id)
            )",

            // 7. Tenant Documents
            "CREATE TABLE IF NOT EXISTS tenant_documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                file_path TEXT NOT NULL,
                file_name TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(tenant_id) REFERENCES tenants(id)
            )",

            // 8. Unit Asset Photos
            "CREATE TABLE IF NOT EXISTS unit_asset_photos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                asset_id INTEGER NOT NULL,
                file_path TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(asset_id) REFERENCES unit_assets(id)
            )",

            // 9. Expense Categories
            "CREATE TABLE IF NOT EXISTS expense_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                display_order INTEGER DEFAULT 0,
                is_active BOOLEAN DEFAULT 1,
                is_system BOOLEAN DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )",

            // 10. System Settings
            "CREATE TABLE IF NOT EXISTS system_settings (
                setting_key TEXT PRIMARY KEY,
                setting_value TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )"
        ];

        foreach ($commands as $cmd) {
            $pdo->exec($cmd);
        }

        if (!self::hasColumn($pdo, 'tenants', 'share_code')) {
            $pdo->exec("ALTER TABLE tenants ADD COLUMN share_code TEXT");
        }

        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_tenants_share_code ON tenants(share_code)");

        $missing_codes = $pdo->query("SELECT id FROM tenants WHERE is_active = 1 AND (share_code IS NULL OR share_code = '')")->fetchAll();
        if (!empty($missing_codes)) {
            $stmt = $pdo->prepare("UPDATE tenants SET share_code = ? WHERE id = ?");
            foreach ($missing_codes as $row) {
                $code = self::generateShareCode($pdo);
                $stmt->execute([$code, $row['id']]);
            }
        }

        // Insert default expense categories if table is empty
        $count = $pdo->query("SELECT COUNT(*) FROM expense_categories")->fetchColumn();
        if ($count == 0) {
            $defaultCategories = [
                ['電費', 1, 1],
                ['修繕費', 2, 1],
                ['網路費', 3, 1],
                ['水費', 4, 1],
                ['雜支', 5, 1]
            ];
            
            $stmt = $pdo->prepare("INSERT INTO expense_categories (name, display_order, is_system) VALUES (?, ?, ?)");
            foreach ($defaultCategories as $cat) {
                $stmt->execute($cat);
            }
        }
    }

    public static function getSetting(PDO $pdo, string $key, $default = null) {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    }

    public static function setSetting(PDO $pdo, string $key, string $value): void {
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, datetime('now'))");
        $stmt->execute([$key, $value]);
    }
}
