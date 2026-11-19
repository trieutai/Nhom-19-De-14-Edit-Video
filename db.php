<?php
// Video Tool / De tai 14. XAMPP: adjust database credentials here if needed.
$host = 'localhost';
$dbname = 'VideoToolDB';
$username = 'root';
$password = '';
$pdo = new PDO("mysql:host={$host};charset=utf8mb4", $username, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$dbname`");
$pdo->exec("CREATE TABLE IF NOT EXISTS videos (
    id INT AUTO_INCREMENT PRIMARY KEY, filename VARCHAR(255) NOT NULL,
    filepath VARCHAR(255) NOT NULL, duration DOUBLE NOT NULL DEFAULT 0,
    width INT NOT NULL DEFAULT 0, height INT NOT NULL DEFAULT 0,
    has_audio TINYINT(1) NOT NULL DEFAULT 0, thumbnail_path VARCHAR(255) NULL,
    file_size BIGINT NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS presets (
    id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(100) NOT NULL,
    width INT NOT NULL, height INT NOT NULL, keep_audio TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB");
if ((int)$pdo->query('SELECT COUNT(*) FROM presets')->fetchColumn() === 0) {
    $pdo->exec("INSERT INTO presets (name,width,height,keep_audio) VALUES
      ('SD 480p (854x480)',854,480,1),
      ('HD 720p (1280x720)',1280,720,1),
      ('Full HD 1080p (1920x1080)',1920,1080,1),
      ('TikTok / Reels Doc (1080x1920)',1080,1920,1)");
}
$pdo->exec("CREATE TABLE IF NOT EXISTS jobs (
    id INT AUTO_INCREMENT PRIMARY KEY, video_id INT NOT NULL, preset_id INT NOT NULL,
    start_time DOUBLE NOT NULL DEFAULT 0, end_time DOUBLE NOT NULL DEFAULT 10,
    status ENUM('pending','processing','completed','error','cancelled') NOT NULL DEFAULT 'pending',
    error_message TEXT NULL, active_attempt_id INT NULL,
    cancel_requested TINYINT(1) NOT NULL DEFAULT 0, heartbeat_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_jobs_status (status,id)
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS job_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY, job_id INT NOT NULL, attempt_number INT NOT NULL,
    status ENUM('processing','success','failed','cancelled') NOT NULL DEFAULT 'processing',
    log_output MEDIUMTEXT NULL, executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL, UNIQUE KEY uniq_job_attempt (job_id,attempt_number)
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS outputs (
    id INT AUTO_INCREMENT PRIMARY KEY, job_id INT NOT NULL, attempt_id INT NULL,
    output_path VARCHAR(255) NOT NULL, thumbnail_path VARCHAR(255) NULL,
    file_size BIGINT NOT NULL DEFAULT 0, duration DOUBLE NULL,
    width INT NULL, height INT NULL, has_audio TINYINT(1) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_outputs_attempt (attempt_id)
) ENGINE=InnoDB");
// Safe, additive migrations for older installations. Existing source videos, jobs, attempts are kept.
function videoToolEnsureColumn(PDO $pdo, $table, $column, $definition) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table,$column]);
    if (!(int)$stmt->fetchColumn()) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
}
foreach ([
    ['videos','has_audio','TINYINT(1) NOT NULL DEFAULT 0'],
    ['videos','thumbnail_path','VARCHAR(255) NULL'],
    ['videos','file_size','BIGINT NOT NULL DEFAULT 0'],
    ['jobs','active_attempt_id','INT NULL'],
    ['jobs','cancel_requested','TINYINT(1) NOT NULL DEFAULT 0'],
    ['jobs','heartbeat_at','DATETIME NULL'],
    ['outputs','thumbnail_path','VARCHAR(255) NULL'],
    ['outputs','duration','DOUBLE NULL'],
    ['outputs','width','INT NULL'],
    ['outputs','height','INT NULL'],
    ['outputs','has_audio','TINYINT(1) NULL'],
    ['job_attempts','finished_at','DATETIME NULL'],
] as $entry) videoToolEnsureColumn($pdo,...$entry);
$pdo->exec("ALTER TABLE job_attempts MODIFY COLUMN status ENUM('processing','success','failed','cancelled') NOT NULL DEFAULT 'processing'");
// Pre-existing rows have no active attempt, and remain untouched.
// Account isolation: existing rows are retained as unassigned, NEVER silently claimed.
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");
videoToolEnsureColumn($pdo,'videos','user_id','INT NULL DEFAULT NULL');
videoToolEnsureColumn($pdo,'jobs','user_id','INT NULL DEFAULT NULL');
$pdo->exec("CREATE INDEX IF NOT EXISTS vt_idx_user_videos ON videos(user_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS vt_idx_user_jobs ON jobs(user_id)");
$pdo->exec("CREATE TABLE IF NOT EXISTS images (
    id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,filepath VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_image_owner(user_id)
) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS image_outputs (
    id INT AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,source_image_id INT NOT NULL,filepath VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_image_out_owner(user_id)
) ENGINE=InnoDB");
