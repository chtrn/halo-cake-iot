<?php
/**
 * Konfigurasi lokal HaloCake. Sesuaikan dengan MySQL/MariaDB di Laragon.
 * PENTING: ganti API_INGEST_KEY sebelum dipakai di jaringan publik.
 */
return [
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => 'halocake_monitoring',
    
    
    
    
    'db_user' => 'root',
    'db_pass' => '12345678',
    'ingest_key' => 'halocake-dev-2026-GANTI-DENGAN-KUNCI-RAHASIA',
    'crossing_id' => 1,
    'online_after_seconds' => 30,
];
