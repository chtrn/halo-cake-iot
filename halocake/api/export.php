<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_method('GET');
try {
    $q = trim((string)($_GET['q'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $date = trim((string)($_GET['date'] ?? ''));
    if (strlen($q) > 100 || ($status !== '' && !in_array($status, ['Idle','Warning','Closed'], true))) {
        api_error(422, 'Filter tidak valid.');
    }
    if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        || !valid_date($date))) {
        api_error(422, 'Tanggal tidak valid.');
    }
    $where = 'crossing_id = :id';
    $params = ['id' => crossing_id()];
    if ($q !== '') {
        $where .= ' AND (description LIKE :q OR CAST(id AS CHAR) LIKE :q_id)';
        $params['q'] = '%' . $q . '%';
        $params['q_id'] = '%' . (ltrim(preg_replace('/[^0-9]/', '', $q), '0') ?: '0') . '%';
        if (!preg_match('/^(#?ACT[- ]?)?\d+$/i', $q)) {
            $where = str_replace(' OR CAST(id AS CHAR) LIKE :q_id', '', $where);
            unset($params['q_id']);
        }
    }
    if ($status !== '') { $where .= ' AND system_status = :status'; $params['status'] = $status; }
    if ($date !== '') { $where .= ' AND DATE(created_at) = :date'; $params['date'] = $date; }
    $stmt = db()->prepare("SELECT id, created_at, description, system_status, carriage_count
        FROM activities WHERE $where ORDER BY created_at DESC, id DESC");
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="riwayat-halocake-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF"); // BOM supaya karakter Indonesia terbaca di Excel.
    fputcsv($out, ['ID', 'Tanggal & waktu (WIB)', 'Aktivitas', 'Status sistem', 'Jumlah gerbong'], ',', '"', '');
    while ($r = $stmt->fetch()) {
        $desc = (string)$r['description'];
        // Cegah formula injection jika CSV dibuka di spreadsheet.
        if (preg_match('/^[=+@\-\t\r]/u', $desc)) $desc = "'" . $desc;
        fputcsv($out, [sprintf('#ACT-%06d', (int)$r['id']), $r['created_at'], $desc, $r['system_status'], (int)$r['carriage_count']], ',', '"', '');
    }
    fclose($out);
} catch (Throwable $e) {
    error_log('[HaloCake export] ' . $e->getMessage());
    if (!headers_sent()) api_error(500, 'Gagal mengekspor CSV.');
}
