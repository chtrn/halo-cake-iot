<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_method('GET');
safe_run(function (): void {
    $pdo = db();
    $id = crossing_id();
    $query = $pdo->prepare(
        'SELECT c.name, c.location, s.system_status, s.barrier_status,
                s.sensor_1, s.sensor_2, s.current_carriages, s.last_seen_at
         FROM crossings c JOIN crossing_states s ON s.crossing_id = c.id
         WHERE c.id = ?'
    );
    $query->execute([$id]);
    $live = $query->fetch();
    if (!$live) api_error(404, 'Perlintasan tidak ditemukan.');

    $stats = $pdo->prepare(
        'SELECT DATE(started_at) AS day, COUNT(*) AS trains,
                COALESCE(SUM(max_carriages), 0) AS carriages,
                ROUND(AVG(CASE
                    WHEN barrier_closed_at IS NOT NULL AND barrier_open_at IS NOT NULL
                    THEN TIMESTAMPDIFF(SECOND, barrier_closed_at, barrier_open_at)
                    ELSE NULL END)) AS average_close_seconds
         FROM train_passages
         WHERE crossing_id = ?
           AND started_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
           AND started_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
         GROUP BY DATE(started_at)'
    );
    $stats->execute([$id]);
    $byDay = [];
    foreach ($stats->fetchAll() as $r) $byDay[$r['day']] = $r;

    $days = [];
    $totalTrains = 0;
    $today = new DateTimeImmutable('today');
    for ($i = 6; $i >= 0; --$i) {
        $date = $today->modify("-{$i} days")->format('Y-m-d');
        $r = $byDay[$date] ?? null;
        $trains = $r ? (int)$r['trains'] : 0;
        $totalTrains += $trains;
        $days[] = [
            'date' => $date,
            'label' => $today->modify("-{$i} days")->format('d M'),
            'trains' => $trains,
            'carriages' => $r ? (int)$r['carriages'] : 0,
            'average_close_seconds' => $r && $r['average_close_seconds'] !== null
                ? (int)$r['average_close_seconds'] : null,
        ];
    }

    $lastSeen = $live['last_seen_at'];
    $age = $lastSeen === null ? null : max(0, time() - strtotime($lastSeen));
    $recent = $age !== null && $age <= (int)config()['online_after_seconds'];
    $todayRecord = $days[6];
    $recap = array_reverse(array_slice($days, -3));
    foreach ($recap as &$r) {
        $r['average_close_label'] = seconds_label($r['average_close_seconds']);
    }
    unset($r);

    json_response(200, [
        'ok' => true,
        'crossing' => ['id' => $id, 'name' => $live['name'], 'location' => $live['location']],
        'live' => [
            'system_status' => $live['system_status'],
            'barrier_status' => $live['barrier_status'],
            'sensor_1' => (bool)$live['sensor_1'],
            'sensor_2' => (bool)$live['sensor_2'],
            'current_carriages' => (int)$live['current_carriages'],
            'data_recent' => $recent,
            'last_seen_at' => $lastSeen,
        ],
        'statistics' => [
            'total_trains_7_days' => $totalTrains,
            'total_carriages_today' => $todayRecord['carriages'],
            'average_close_seconds_today' => $todayRecord['average_close_seconds'],
            'average_close_label_today' => seconds_label($todayRecord['average_close_seconds']),
            'daily' => $days,
            'recap' => $recap,
        ],
        'server_time_wib' => date('Y-m-d H:i:s'),
    ]);
});
