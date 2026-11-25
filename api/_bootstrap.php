<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');
ini_set('display_errors', '0');

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require dirname(__DIR__) . '/config/app.php';
    }
    return $cfg;
}

function json_response(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function api_error(int $status, string $message): never
{
    json_response($status, ['ok' => false, 'message' => $message]);
}

function require_method(string $expected): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $expected) {
        header('Allow: ' . $expected);
        api_error(405, 'Metode HTTP tidak diizinkan.');
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $cfg = config();
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['db_host'], (int)$cfg['db_port'], $cfg['db_name']);
        $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Seluruh waktu dari database dan aplikasi menggunakan WIB.
        $pdo->exec("SET time_zone = '+07:00'");
    }
    return $pdo;
}

function crossing_id(): int
{
    return (int) config()['crossing_id'];
}

function safe_run(callable $fn): void
{
    try {
        $fn();
    } catch (PDOException $e) {
        error_log('[HaloCake SQL] ' . $e->getMessage());
        api_error(500, 'Koneksi/query database gagal. Periksa konfigurasi dan impor schema.sql.');
    } catch (Throwable $e) {
        error_log('[HaloCake API] ' . $e->getMessage());
        api_error(500, 'Terjadi kesalahan server. Periksa log PHP.');
    }
}

function seconds_label(?int $seconds): string
{
    if ($seconds === null) return '—';
    return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
}

function valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}
