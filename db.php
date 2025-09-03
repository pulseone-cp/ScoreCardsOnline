<?php
// Database connection and versioned migrations from SQL files in migrations/ folder

function db_get_connection(): mysqli {
    static $conn = null;
    if ($conn !== null) return $conn;

    $dbhost = getenv('DBHOST');
    $dbuser = getenv('DBUSER');
    $dbpassword = getenv('DBPASSWORD');
    $dbname = getenv('DBNAME');

    if (!$dbhost || !$dbuser || !$dbname) {
        http_response_code(500);
        die('Database environment variables are not set (DBHOST, DBUSER, DBPASSWORD, DBNAME).');
    }

    $conn = @new mysqli($dbhost, $dbuser, $dbpassword, $dbname);
    if ($conn->connect_error) {
        http_response_code(500);
        die('Database connection failed: ' . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');

    db_run_migrations($conn);
    // Auto-heal critical schema differences (e.g., missing mod_hash) in case migrations were skipped or version bumped manually
    db_autoheal_schema($conn);

    return $conn;
}

function db_table_exists(mysqli $db, string $table): bool {
    $res = $db->query("SHOW TABLES LIKE '" . $db->real_escape_string($table) . "'");
    return $res && $res->num_rows > 0;
}

function db_get_current_version(mysqli $db): int {
    // ensure config table exists
    $db->query("CREATE TABLE IF NOT EXISTS config (
        k VARCHAR(64) PRIMARY KEY,
        v VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $stmt = $db->prepare('SELECT v FROM config WHERE k=\'db_version\'' );
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            return (int)$row['v'];
        }
    }
    // initialize to 0 if not set
    $db->query("INSERT IGNORE INTO config (k, v) VALUES ('db_version', '0')");
    return 0;
}

function db_set_current_version(mysqli $db, int $version): void {
    $stmt = $db->prepare('REPLACE INTO config (k, v) VALUES (\'db_version\', ?)');
    $val = (string)$version;
    $stmt->bind_param('s', $val);
    $stmt->execute();
}

function db_run_migrations(mysqli $db): void {
    $migrationsDir = __DIR__ . DIRECTORY_SEPARATOR . 'migrations';
    if (!is_dir($migrationsDir)) {
        // no migrations directory, nothing to do
        return;
    }

    $files = scandir($migrationsDir);
    $migrations = [];
    foreach ($files as $f) {
        if (preg_match('/^schema(\d{3})\.sql$/', $f, $m)) {
            $migrations[(int)$m[1]] = $migrationsDir . DIRECTORY_SEPARATOR . $f;
        }
    }
    if (empty($migrations)) return;

    ksort($migrations, SORT_NUMERIC);

    $current = db_get_current_version($db);
    $latest = array_key_last($migrations);

    if ($current >= $latest) return; // up to date

    foreach ($migrations as $ver => $path) {
        if ($ver <= $current) continue;
        $sql = file_get_contents($path);
        if ($sql === false) {
            http_response_code(500);
            die('Failed to read migration file: ' . $path);
        }
        // Execute multi-statement SQL safely
        $db->begin_transaction();
        try {
            // Temporarily disable FK checks to avoid ordering/circular dependency issues during migration
            $db->query('SET FOREIGN_KEY_CHECKS=0');

            // Strip SQL comments to ensure statements aren't skipped when a comment precedes them
            $noComments = preg_replace('/\/\*.*?\*\//s', '', $sql); // block comments
            $noComments = preg_replace('/^\s*--.*$/m', '', $noComments); // line comments '--'
            $noComments = preg_replace('/^\s*#.*$/m', '', $noComments); // line comments '#'

            // Split statements on semicolon followed by optional whitespace and end-of-line or EOF
            $delimiterSplit = preg_split('/;\s*(?:\r?\n|$)/', $noComments);
            foreach ($delimiterSplit as $stmtSql) {
                $stmtSql = trim($stmtSql);
                if ($stmtSql === '') continue;
                if (!$db->query($stmtSql)) {
                    throw new Exception('Migration v' . $ver . ' failed executing: ' . $stmtSql . ' :: ' . $db->error);
                }
            }
            db_set_current_version($db, $ver);
            // Re-enable FK checks after successful migration
            $db->query('SET FOREIGN_KEY_CHECKS=1');
            $db->commit();
        } catch (Throwable $e) {
            // Ensure FK checks are re-enabled on failure as well
            try { $db->query('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $ignored) {}
            $db->rollback();
            http_response_code(500);
            die('Database migration error: ' . $e->getMessage());
        }
    }

}


function db_column_exists(mysqli $db, string $table, string $column): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

function db_unique_index_on_column_exists(mysqli $db, string $table, string $column): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND NON_UNIQUE = 0 LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

function db_autoheal_schema(mysqli $db): void {
    try {
        if (!db_table_exists($db, 'rooms')) return;

        // Ensure rooms.mod_hash column exists
        if (!db_column_exists($db, 'rooms', 'mod_hash')) {
            $db->query('ALTER TABLE rooms ADD COLUMN mod_hash VARCHAR(64) NULL AFTER hash');
        }

        // Backfill any NULL or empty moderator hashes with unique tokens
        $db->query("UPDATE rooms SET mod_hash = SUBSTRING(REPLACE(UUID(),'-',''), 1, 16) WHERE mod_hash IS NULL OR mod_hash = ''");

        // Enforce NOT NULL for the column
        $db->query('ALTER TABLE rooms MODIFY mod_hash VARCHAR(64) NOT NULL');

        // Ensure a UNIQUE index exists on mod_hash
        if (!db_unique_index_on_column_exists($db, 'rooms', 'mod_hash')) {
            // Use a deterministic name compatible with migration 002
            $db->query('CREATE UNIQUE INDEX uniq_rooms_mod_hash ON rooms (mod_hash)');
        }

        // Ensure laps.voting_open column exists with default 1
        if (db_table_exists($db, 'laps') && !db_column_exists($db, 'laps', 'voting_open')) {
            $db->query('ALTER TABLE laps ADD COLUMN voting_open TINYINT(1) NOT NULL DEFAULT 1');
        }
    } catch (Throwable $e) {
        // Silently ignore auto-heal issues to avoid breaking the app if DB permissions are limited.
    }
}
