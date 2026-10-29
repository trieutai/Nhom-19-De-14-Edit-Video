<?php
$host = 'localhost';$dbname = 'VideoToolDB';
$username = 'root';$password = '';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $username,$password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbname`");

    // 1. Bảng lưu video gốc
    $pdo->exec("CREATE TABLE IF NOT EXISTS videos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        filepath VARCHAR(255) NOT NULL,
        duration FLOAT DEFAULT 0,
        width INT DEFAULT 0,
        height INT DEFAULT 0,
        has_audio TINYINT(1) DEFAULT 1,
        file_size BIGINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // 2. Bảng cấu hình Preset đầu ra
    $pdo->exec("CREATE TABLE IF NOT EXISTS presets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        width INT NOT NULL,
        height INT NOT NULL,
        keep_audio TINYINT(1) DEFAULT 1
    )");

    if ($pdo->query("SELECT COUNT(*) FROM presets")->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO presets (name, width, height, keep_audio) VALUES 
        ('HD 720p (1280x720)', 1280, 720, 1),
        ('Full HD 1080p (1920x1080)', 1920, 1080, 1),
        ('TikTok / Reels Dọc (1080x1920)', 1080, 1920, 1)");
    }

    // 3. Bảng hàng đợi Jobs
    $pdo->exec("CREATE TABLE IF NOT EXISTS jobs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        video_id INT NOT NULL,
        preset_id INT NOT NULL,
        start_time FLOAT DEFAULT 0,
        end_time FLOAT DEFAULT 10,
        status ENUM('pending', 'processing', 'completed', 'error', 'cancelled') DEFAULT 'pending',
        error_message TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (video_id) REFERENCES videos(id) ON DELETE CASCADE,
        FOREIGN KEY (preset_id) REFERENCES presets(id) ON DELETE CASCADE
    )");

    // 4. Bảng lịch sử các lần chạy độc lập (Job Attempts)
    $pdo->exec("CREATE TABLE IF NOT EXISTS job_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        attempt_number INT NOT NULL,
        status ENUM('success', 'failed') NOT NULL,
        log_output TEXT,
        executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
    )");

    // 5. Bảng kết quả đầu ra (Outputs & Thumbnails)
    $pdo->exec("CREATE TABLE IF NOT EXISTS outputs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        attempt_id INT,
        output_path VARCHAR(255) NOT NULL,
        thumbnail_path VARCHAR(255),
        file_size BIGINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
    )");

    // ==========================================
    // 6. BẢNG QUẢN LÝ TÀI KHOẢN (MỚI THÊM VÀO)
    // ==========================================
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        email VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

} catch (PDOException $e) {
    die("Lỗi CSDL: " . $e->getMessage());
}
?>