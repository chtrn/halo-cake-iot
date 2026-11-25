<?php
declare(strict_types=1);
/** Local CLI-only, additive schema repair for HaloCake. No DROP/TRUNCATE/DELETE. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Hanya dapat dijalankan melalui terminal Laragon.');
}
require dirname(__DIR__) . '/api/_bootstrap.php';
$apply = in_array('--apply', $argv, true);
$pdo = db();
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($database !== 'halocake_monitoring') {
    fwrite(STDERR, "Dibatalkan: database aktif adalah $database, bukan halocake_monitoring.\n");
    exit(1);
}
// These match the original schema.sql. All statements are additive only.
$create = [
    'crossings' => "CREATE TABLE IF NOT EXISTS crossings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL,
        location VARCHAR(120) NOT NULL) ENGINE=InnoDB",
    'train_passages' => "CREATE TABLE IF NOT EXISTS train_passages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, crossing_id INT UNSIGNED NOT NULL,
        started_at DATETIME NOT NULL, barrier_closed_at DATETIME NULL,
        barrier_open_at DATETIME NULL, ended_at DATETIME NULL,
        max_carriages INT UNSIGNED NOT NULL DEFAULT 0,
        INDEX idx_passages_date (crossing_id, started_at),
        CONSTRAINT fk_passage_crossing FOREIGN KEY (crossing_id) REFERENCES crossings(id)) ENGINE=InnoDB",
    'crossing_states' => "CREATE TABLE IF NOT EXISTS crossing_states (
        crossing_id INT UNSIGNED PRIMARY KEY, system_status VARCHAR(12) NOT NULL DEFAULT 'Idle',
        barrier_status VARCHAR(12) NOT NULL DEFAULT 'Terbuka', sensor_1 TINYINT(1) NOT NULL DEFAULT 0,
        sensor_2 TINYINT(1) NOT NULL DEFAULT 0, current_carriages INT UNSIGNED NOT NULL DEFAULT 0,
        active_passage_id BIGINT UNSIGNED NULL, last_seen_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_state_crossing FOREIGN KEY (crossing_id) REFERENCES crossings(id),
        CONSTRAINT fk_state_passage FOREIGN KEY (active_passage_id) REFERENCES train_passages(id)) ENGINE=InnoDB",
    'activities' => "CREATE TABLE IF NOT EXISTS activities (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, crossing_id INT UNSIGNED NOT NULL,
        event_type VARCHAR(40) NOT NULL, description VARCHAR(255) NOT NULL,
        system_status VARCHAR(12) NOT NULL, carriage_count INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_activities_date (crossing_id, created_at, id),
        INDEX idx_activities_status (crossing_id, system_status),
        CONSTRAINT fk_activities_crossing FOREIGN KEY (crossing_id) REFERENCES crossings(id)) ENGINE=InnoDB",
];
// Only repair missing non-key columns; never guess primary keys / foreign keys.
$addColumns = [
    'crossings' => [
        'name' => "VARCHAR(100) NOT NULL DEFAULT 'Perlintasan 01'",
        'location' => "VARCHAR(120) NOT NULL DEFAULT 'Bandung'",
    ],
    'train_passages' => [
        'started_at' => 'DATETIME NULL',
        'barrier_closed_at' => 'DATETIME NULL',
        'barrier_open_at' => 'DATETIME NULL',
        'ended_at' => 'DATETIME NULL',
        'max_carriages' => 'INT UNSIGNED NOT NULL DEFAULT 0',
    ],
    'crossing_states' => [
        'system_status' => "VARCHAR(12) NOT NULL DEFAULT 'Idle'",
        'barrier_status' => "VARCHAR(12) NOT NULL DEFAULT 'Terbuka'",
        'sensor_1' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'sensor_2' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'current_carriages' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'active_passage_id' => 'BIGINT UNSIGNED NULL',
        'last_seen_at' => 'DATETIME NULL',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ],
    'activities' => [
        'event_type' => "VARCHAR(40) NOT NULL DEFAULT 'system'",
        'description' => "VARCHAR(255) NOT NULL DEFAULT ''",
        'system_status' => "VARCHAR(12) NOT NULL DEFAULT 'Idle'",
        'carriage_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ],
];
$requiredKeys = [
    'crossings' => ['id'],
    'train_passages' => ['id', 'crossing_id'],
    'crossing_states' => ['crossing_id'],
    'activities' => ['id', 'crossing_id'],
];
$existing = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
$operations = [];
foreach ($create as $table => $sql) {
    if (!in_array($table, $existing, true)) {
        $operations[] = [$table, 'create', $sql];
        continue;
    }
    $fields = array_column($pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(), 'Field');
    foreach ($requiredKeys[$table] as $key) {
        if (!in_array($key, $fields, true)) {
            fwrite(STDERR, "Tidak dapat otomatis memperbaiki $table: kolom kunci $key hilang. Backup dan periksa struktur tabel secara manual.\n");
            exit(2);
        }
    }
    foreach ($addColumns[$table] as $column => $definition) {
        if (!in_array($column, $fields, true)) {
            $operations[] = [$table, 'column', "ALTER TABLE `$table` ADD COLUMN `$column` $definition"];
        }
    }
}
// Do not reset or overwrite existing records.
$operations[] = ['crossings', 'seed', "INSERT INTO crossings (id,name,location) VALUES (" . crossing_id() . ", 'Perlintasan 01', 'Bandung') ON DUPLICATE KEY UPDATE id=id"];
$operations[] = ['crossing_states', 'seed', "INSERT INTO crossing_states (crossing_id) VALUES (" . crossing_id() . ") ON DUPLICATE KEY UPDATE crossing_id=crossing_id"];
foreach ($operations as $item) echo "[" . $item[1] . "] " . $item[0] . ": " . $item[2] . "\n";
if (!$apply) {
    echo "\nDRY RUN: database belum diubah. Backup dahulu, lalu jalankan: php tools/repair-schema.php --apply\n";
    exit;
}
foreach ($operations as $item) {
    try {
        $pdo->exec($item[2]);
        echo "OK: {$item[0]} ({$item[1]})\n";
    } catch (PDOException $e) {
        fwrite(STDERR, "GAGAL saat memperbaiki {$item[0]}. {$e->getMessage()}\n");
        exit(3);
    }
}
echo "Selesai. Buka http://localhost/halocake/api/db-diagnose.php untuk tes ulang.\n";
