<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_method('POST');

// Hanya jembatan IoT (Node-RED / ESP32) yang boleh menulis data.
$expected = (string)config()['ingest_key'];
$provided = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
if ($expected === '' || !hash_equals($expected, $provided)) {
    api_error(401, 'X-API-Key salah atau belum dikirim.');
}
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
    api_error(415, 'Gunakan Content-Type: application/json.');
}
if (isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 16384) {
    api_error(413, 'Payload terlalu besar.');
}
$raw = file_get_contents('php://input', false, null, 0, 16385);
if ($raw === false || strlen($raw) > 16384) api_error(413, 'Payload terlalu besar.');
$data = json_decode($raw, true);
if (!is_array($data) || array_is_list($data)) api_error(400, 'Payload harus objek JSON.');

function first_field(array $data, array $keys, bool &$exists): mixed
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            $exists = true;
            return $data[$key];
        }
    }
    $exists = false;
    return null;
}

function bool_field(mixed $value, string $name): int
{
    if (is_bool($value)) return (int)$value;
    if (is_int($value) && ($value === 0 || $value === 1)) return $value;
    if (is_string($value)) {
        $v = strtolower(trim($value));
        if (in_array($v, ['1', 'true', 'on', 'aktif', 'mendeteksi', 'detected'], true)) return 1;
        if (in_array($v, ['0', 'false', 'off', 'nonaktif', 'tidak mendeteksi', 'clear'], true)) return 0;
    }
    api_error(422, "$name harus boolean (true/false atau 1/0).");
}

function system_field(mixed $value): string
{
    if (!is_string($value)) api_error(422, 'statusSistem tidak valid.');
    $v = strtolower(trim($value));
    return match ($v) {
        'idle' => 'Idle',
        'warning', 'peringatan' => 'Warning',
        'closed', 'tertutup' => 'Closed',
        default => api_error(422, 'statusSistem harus Idle, Warning, atau Closed.'),
    };
}

function barrier_field(mixed $value): string
{
    if (!is_string($value)) api_error(422, 'statusPalang tidak valid.');
    return match (strtolower(trim($value))) {
        'terbuka', 'open' => 'Terbuka',
        'tertutup', 'closed' => 'Tertutup',
        'menutup', 'closing' => 'Menutup',
        'membuka', 'opening' => 'Membuka',
        default => api_error(422, 'statusPalang harus Terbuka, Tertutup, Menutup, atau Membuka.'),
    };
}

$hasSystem = false; $hasBarrier = false; $hasS1 = false; $hasS2 = false; $hasCount = false; $hasEvent = false;
$systemRaw = first_field($data, ['statusSistem', 'system_status'], $hasSystem);
$barrierRaw = first_field($data, ['statusPalang', 'barrier_status'], $hasBarrier);
$s1Raw = first_field($data, ['sensor1', 'sensorA', 'sensor_1'], $hasS1);
$s2Raw = first_field($data, ['sensor2', 'sensorB', 'sensor_2'], $hasS2);
$countRaw = first_field($data, ['jumlahGerbong', 'current_carriages'], $hasCount);
$eventRaw = first_field($data, ['aktivitas', 'event'], $hasEvent);
if (!$hasSystem && !$hasBarrier && !$hasS1 && !$hasS2 && !$hasCount && !$hasEvent) {
    api_error(422, 'JSON tidak memiliki field data yang dikenali.');
}
if ($hasSystem) $systemRaw = system_field($systemRaw);
if ($hasBarrier) $barrierRaw = barrier_field($barrierRaw);
if ($hasS1) $s1Raw = bool_field($s1Raw, 'sensor1');
if ($hasS2) $s2Raw = bool_field($s2Raw, 'sensor2');
if ($hasCount) {
    // Validasi angka non-negatif; 0 juga harus diperbarui (bukan diabaikan).
    if (!(is_int($countRaw) || (is_string($countRaw) && ctype_digit($countRaw)))
        || (string)$countRaw === '' || (float)$countRaw > 1000000 || (int)$countRaw < 0) {
        api_error(422, 'jumlahGerbong harus angka bulat antara 0 dan 1000000.');
    }
    $countRaw = (int)$countRaw;
}
if ($hasEvent && (!is_string($eventRaw) || strlen(trim($eventRaw)) > 240)) {
    api_error(422, 'aktivitas/event harus teks maksimal 240 karakter.');
}

safe_run(function () use ($hasSystem, $hasBarrier, $hasS1, $hasS2, $hasCount, $hasEvent,
    $systemRaw, $barrierRaw, $s1Raw, $s2Raw, $countRaw, $eventRaw): void {
    $pdo = db();
    $crossingId = crossing_id();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM crossing_states WHERE crossing_id = ? FOR UPDATE');
        $stmt->execute([$crossingId]);
        $old = $stmt->fetch();
        if (!$old) api_error(404, 'Status perlintasan tidak ditemukan.');

        $barrier = $hasBarrier ? $barrierRaw : $old['barrier_status'];
        $s1 = $hasS1 ? $s1Raw : (int)$old['sensor_1'];
        $s2 = $hasS2 ? $s2Raw : (int)$old['sensor_2'];
        $carriages = $hasCount ? $countRaw : (int)$old['current_carriages'];
        // Jika sistem tidak dikirim, turunkan status dari palang dan sensor yang sudah diketahui.
        if ($hasSystem) $system = $systemRaw;
        elseif ($hasBarrier || $hasS1 || $hasS2) {
            $system = $barrier === 'Tertutup' ? 'Closed' :
                (($s1 || $s2 || in_array($barrier, ['Menutup', 'Membuka'], true)) ? 'Warning' : 'Idle');
        } else $system = $old['system_status'];

        $now = date('Y-m-d H:i:s');
        $passageId = $old['active_passage_id'] === null ? null : (int)$old['active_passage_id'];
        $startNew = ($system !== 'Idle' || $barrier !== 'Terbuka' || $s1 || $s2
            || ($carriages > 0 && (int)$old['current_carriages'] === 0));
        if ($passageId === null && $startNew) {
            $ins = $pdo->prepare('INSERT INTO train_passages (crossing_id, started_at) VALUES (?, ?)');
            $ins->execute([$crossingId, $now]);
            $passageId = (int)$pdo->lastInsertId();
        }
        if ($passageId !== null) {
            $update = $pdo->prepare(
                'UPDATE train_passages SET max_carriages = GREATEST(max_carriages, :carriages),
                 barrier_closed_at = CASE WHEN barrier_closed_at IS NULL AND :barrier_close = 1
                   THEN :now_closed ELSE barrier_closed_at END,
                 barrier_open_at = CASE WHEN barrier_closed_at IS NOT NULL AND barrier_open_at IS NULL
                   AND :barrier_open = 1 THEN :now_open ELSE barrier_open_at END
                 WHERE id = :id AND crossing_id = :crossing_id'
            );
            $update->execute([
                'carriages' => $carriages,
                'barrier_close' => $barrier === 'Tertutup' ? 1 : 0,
                'now_closed' => $now,
                'barrier_open' => $barrier === 'Terbuka' ? 1 : 0,
                'now_open' => $now,
                'id' => $passageId,
                'crossing_id' => $crossingId,
            ]);
        }

        $log = $pdo->prepare('INSERT INTO activities
            (crossing_id, event_type, description, system_status, carriage_count, created_at)
            VALUES (?, ?, ?, ?, ?, ?)');
        $activityCount = 0;
        $record = function (string $kind, string $text) use ($log, $crossingId, $system, $carriages, $now, &$activityCount): void {
            $log->execute([$crossingId, $kind, $text, $system, $carriages, $now]);
            ++$activityCount;
        };
        if ($system !== $old['system_status']) {
            $record('system', "Status sistem: {$old['system_status']} → $system");
        }
        if ($barrier !== $old['barrier_status']) {
            $record('barrier', "Palang berubah: {$old['barrier_status']} → $barrier");
        }
        if ($s1 !== (int)$old['sensor_1']) {
            $record('sensor_1', $s1 ? 'Sensor 01 mendeteksi kereta' : 'Sensor 01 tidak lagi mendeteksi');
        }
        if ($s2 !== (int)$old['sensor_2']) {
            $record('sensor_2', $s2 ? 'Sensor 02 mendeteksi kereta' : 'Sensor 02 tidak lagi mendeteksi');
        }
        if ($carriages > (int)$old['current_carriages']) {
            $record('carriages', "Gerbong ke-$carriages terdeteksi");
        }
        if ($hasEvent && trim($eventRaw) !== '') $record('custom', trim($eventRaw));

        $finished = $passageId !== null && $system === 'Idle' && $barrier === 'Terbuka' && !$s1 && !$s2;
        if ($finished) {
            $finish = $pdo->prepare('UPDATE train_passages SET ended_at = ? WHERE id = ? AND ended_at IS NULL');
            $finish->execute([$now, $passageId]);
            $passageId = null;
        }

        $save = $pdo->prepare('UPDATE crossing_states SET system_status = ?, barrier_status = ?,
            sensor_1 = ?, sensor_2 = ?, current_carriages = ?, active_passage_id = ?,
            last_seen_at = ?, updated_at = ? WHERE crossing_id = ?');
        $save->execute([$system, $barrier, $s1, $s2, $carriages, $passageId, $now, $now, $crossingId]);
        $pdo->commit();
        json_response(200, [
            'ok' => true,
            'message' => 'Data perangkat tersimpan',
            'state' => ['statusSistem' => $system, 'statusPalang' => $barrier,
                'sensor1' => (bool)$s1, 'sensor2' => (bool)$s2, 'jumlahGerbong' => $carriages],
            'new_activities' => $activityCount,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
});
