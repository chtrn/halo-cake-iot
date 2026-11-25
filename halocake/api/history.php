<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_method('GET');
safe_run(function (): void {
    $pdo = db();
    $q = trim((string)($_GET['q'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $date = trim((string)($_GET['date'] ?? ''));
    $page = min(100000, max(1, (int)($_GET['page'] ?? 1)));
    $pageSize = 8;
    if (strlen($q) > 100) api_error(422, 'Kata kunci terlalu panjang.');
    if ($status !== '' && !in_array($status, ['Idle', 'Warning', 'Closed'], true)) api_error(422, 'Filter status tidak valid.');
    if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        || !valid_date($date))) {
        api_error(422, 'Tanggal tidak valid.');
    }
    $where = 'crossing_id = :crossing_id';
    $params = ['crossing_id' => crossing_id()];
    if ($q !== '') {
        $where .= ' AND (description LIKE :q OR CAST(id AS CHAR) LIKE :q_id)';
        $params['q'] = '%' . $q . '%';
        $params['q_id'] = '%' . (ltrim(preg_replace('/[^0-9]/', '', $q), '0') ?: '0') . '%';
        // Jika kata kunci bukan ID, hanya cari uraian (bukan semua ID).
        if (!preg_match('/^(#?ACT[- ]?)?\d+$/i', $q)) {
            $where = str_replace(' OR CAST(id AS CHAR) LIKE :q_id', '', $where);
            unset($params['q_id']);
        }
    }
    if ($status !== '') { $where .= ' AND system_status = :status'; $params['status'] = $status; }
    if ($date !== '') { $where .= ' AND DATE(created_at) = :date'; $params['date'] = $date; }

    $count = $pdo->prepare("SELECT COUNT(*) FROM activities WHERE $where");
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = min($page, $pages);
    $offset = ($page - 1) * $pageSize;
    $query = $pdo->prepare(
        "SELECT id, created_at, description, system_status, carriage_count
         FROM activities WHERE $where ORDER BY created_at DESC, id DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $value) $query->bindValue(':' . $key, $value);
    $query->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $query->bindValue(':offset', $offset, PDO::PARAM_INT);
    $query->execute();
    $rows = array_map(static function (array $r): array {
        return [
            'id' => sprintf('#ACT-%06d', (int)$r['id']),
            'date' => $r['created_at'],
            'description' => $r['description'],
            'status' => $r['system_status'],
            'carriages' => (int)$r['carriage_count'],
        ];
    }, $query->fetchAll());
    json_response(200, ['ok' => true, 'rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages]);
});
