<?php
declare(strict_types=1);
/**
 * LOCAL-ONLY schema + query checker. Delete after debugging.
 * Do not expose sensitive database errors on public servers.
 */
require __DIR__ . '/_bootstrap.php';
require_method('GET');
header('Cache-Control: no-store');
$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)) {
    api_error(403, 'Pemeriksaan database hanya boleh dijalankan dari localhost.');
}

$required = [
    'crossings' => ['id','name','location'],
    'crossing_states' => ['crossing_id','system_status','barrier_status','sensor_1','sensor_2','current_carriages','last_seen_at'],
    'train_passages' => ['id','crossing_id','started_at','barrier_closed_at','barrier_open_at','max_carriages'],
    'activities' => ['id','crossing_id','created_at','description','system_status','carriage_count'],
];

try {
    $pdo = db();
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN);
    $report = [];
    $missingTables = [];
    $missingColumns = [];
    foreach ($required as $table => $columns) {
        if (!in_array($table, $tables, true)) {
            $report[$table] = ['exists' => false, 'missing_columns' => $columns];
            $missingTables[] = $table;
            continue;
        }
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
        $found = array_column($stmt->fetchAll(), 'Field');
        $missing = array_values(array_diff($columns, $found));
        $report[$table] = ['exists' => true, 'missing_columns' => $missing];
        if ($missing) $missingColumns[$table] = $missing;
    }

    $tests = [];
    $queries = [
        'dashboard_live' => ['SELECT c.name, c.location, s.system_status, s.barrier_status, s.sensor_1, s.sensor_2, s.current_carriages, s.last_seen_at
          FROM crossings c JOIN crossing_states s ON s.crossing_id = c.id WHERE c.id = ?', [crossing_id()]],
        'dashboard_statistics' => ['SELECT DATE(started_at) AS day, COUNT(*) AS trains, COALESCE(SUM(max_carriages), 0) AS carriages,
          ROUND(AVG(CASE WHEN barrier_closed_at IS NOT NULL AND barrier_open_at IS NOT NULL
          THEN TIMESTAMPDIFF(SECOND, barrier_closed_at, barrier_open_at) ELSE NULL END)) AS average_close_seconds
          FROM train_passages WHERE crossing_id = ? AND started_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
          AND started_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY) GROUP BY DATE(started_at)', [crossing_id()]],
        'history_count' => ['SELECT COUNT(*) FROM activities WHERE crossing_id = ?', [crossing_id()]],
        'history_rows' => ['SELECT id, created_at, description, system_status, carriage_count FROM activities
          WHERE crossing_id = :crossing_id ORDER BY created_at DESC, id DESC LIMIT :lim OFFSET :off',
            ['crossing_id' => crossing_id(), 'lim' => 8, 'off' => 0]],
    ];
    foreach ($queries as $name => [$sql, $params]) {
        try {
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue(is_int($key) ? $key + 1 : ':' . $key, $value, PDO::PARAM_INT);
            }
            $stmt->execute();
            $tests[$name] = ['ok' => true];
        } catch (PDOException $e) {
            $tests[$name] = [
                'ok' => false,
                'sqlstate' => $e->errorInfo[0] ?? $e->getCode(),
                'mysql_code' => $e->errorInfo[1] ?? null,
                'reason' => $e->getMessage(),
            ];
        }
    }
    $passed = !$missingTables && !$missingColumns;
    foreach ($tests as $test) $passed = $passed && $test['ok'];
    json_response($passed ? 200 : 500, [
        'ok' => $passed,
        'database' => $database,
        'tables' => $report,
        'missing_tables' => $missingTables,
        'missing_columns' => $missingColumns,
        'query_tests' => $tests,
        'next_step' => $passed
            ? 'Skema dan query lulus. Periksa respons kedua API dan log PHP bila masih error.'
            : 'Jalankan tools/repair-schema.php --apply dari terminal Laragon setelah mem-backup database. Lalu jalankan pemeriksaan ulang.',
    ]);
} catch (Throwable $e) {
    error_log('[HaloCake diagnosis] ' . $e->getMessage());
    json_response(500, [
        'ok' => false,
        'error' => 'Pemeriksaan gagal sebelum semua query dieksekusi.',
        'reason_local_only' => $e->getMessage(),
    ]);
}
