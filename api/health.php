<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_method('GET');
safe_run(function (): void {
    $pdo = db();
    $name = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM crossing_states WHERE crossing_id = ?');
    $stmt->execute([crossing_id()]);
    json_response(200, [
        'ok' => true,
        'database' => $name,
        'crossing_configured' => (bool) $stmt->fetchColumn(),
        'time_wib' => date('Y-m-d H:i:s'),
    ]);
});
