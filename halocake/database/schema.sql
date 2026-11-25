-- Jalankan sekali melalui HeidiSQL (Laragon > Database > Load SQL file) atau phpMyAdmin bila tersedia.
-- Database dimulai TANPA riwayat palsu; statistik bertambah dari data perangkat.
CREATE DATABASE IF NOT EXISTS halocake_monitoring
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE halocake_monitoring;

CREATE TABLE IF NOT EXISTS crossings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  location VARCHAR(120) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS train_passages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  crossing_id INT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  barrier_closed_at DATETIME NULL,
  barrier_open_at DATETIME NULL,
  ended_at DATETIME NULL,
  max_carriages INT UNSIGNED NOT NULL DEFAULT 0,
  INDEX idx_passages_date (crossing_id, started_at),
  CONSTRAINT fk_passage_crossing FOREIGN KEY (crossing_id) REFERENCES crossings(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS crossing_states (
  crossing_id INT UNSIGNED PRIMARY KEY,
  system_status VARCHAR(12) NOT NULL DEFAULT 'Idle',
  barrier_status VARCHAR(12) NOT NULL DEFAULT 'Terbuka',
  sensor_1 TINYINT(1) NOT NULL DEFAULT 0,
  sensor_2 TINYINT(1) NOT NULL DEFAULT 0,
  current_carriages INT UNSIGNED NOT NULL DEFAULT 0,
  active_passage_id BIGINT UNSIGNED NULL,
  last_seen_at DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_state_crossing FOREIGN KEY (crossing_id) REFERENCES crossings(id),
  CONSTRAINT fk_state_passage FOREIGN KEY (active_passage_id) REFERENCES train_passages(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  crossing_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(40) NOT NULL,
  description VARCHAR(255) NOT NULL,
  system_status VARCHAR(12) NOT NULL,
  carriage_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_activities_date (crossing_id, created_at, id),
  INDEX idx_activities_status (crossing_id, system_status),
  CONSTRAINT fk_activities_crossing FOREIGN KEY (crossing_id) REFERENCES crossings(id)
) ENGINE=InnoDB;

INSERT INTO crossings (id, name, location)
VALUES (1, 'Perlintasan 01', 'Bandung')
ON DUPLICATE KEY UPDATE id = id;
INSERT INTO crossing_states (crossing_id)
VALUES (1)
ON DUPLICATE KEY UPDATE crossing_id = crossing_id;
