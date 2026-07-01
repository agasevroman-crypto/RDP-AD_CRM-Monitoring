<?php
/**
 * db.php — Database connection and schema management
 * All database tables, indexes, and seed data are handled here.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'apgk_rdp');
define('DB_USER', 'apgk_rdp');
define('DB_PASS', 'orrz20054Q+');

function get_db_connection(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES    => false,
        ]
    );

    init_schema($pdo);
    return $pdo;
}

function init_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('super_admin','admin') NOT NULL DEFAULT 'admin',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ((int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() === 0) {
        $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'super_admin')")
            ->execute(['admin', password_hash('admin', PASSWORD_DEFAULT)]);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_servers (
        admin_id INT NOT NULL,
        server_name VARCHAR(100) NOT NULL,
        PRIMARY KEY (admin_id, server_name),
        FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(255) UNIQUE NOT NULL,
        name VARCHAR(100) NOT NULL,
        permissions VARCHAR(255) NOT NULL DEFAULT 'rdp,ad',
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ad_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        dc_name VARCHAR(100) NOT NULL,
        event_id INT NOT NULL,
        action_type VARCHAR(100) NOT NULL,
        target_user VARCHAR(255) NOT NULL,
        caller_user VARCHAR(255) NOT NULL,
        details TEXT,
        INDEX idx_ad_ts (timestamp),
        INDEX idx_ad_dc (dc_name),
        INDEX idx_ad_target (target_user)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rdp_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        server_name VARCHAR(100) NOT NULL,
        username VARCHAR(100) NOT NULL,
        session_id INT NOT NULL,
        event_type VARCHAR(100) NOT NULL,
        ip_address VARCHAR(100) DEFAULT 'Unknown',
        INDEX idx_rdp_ts (timestamp),
        INDEX idx_rdp_srv (server_name),
        INDEX idx_rdp_user (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rdp_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        server_name VARCHAR(100) NOT NULL,
        username VARCHAR(100) NOT NULL,
        session_id INT NOT NULL,
        ip_address VARCHAR(100) DEFAULT 'Unknown',
        start_time TIMESTAMP NOT NULL,
        end_time TIMESTAMP NULL,
        duration_seconds INT DEFAULT 0,
        INDEX idx_sess_start (start_time),
        INDEX idx_sess_end (end_time),
        INDEX idx_sess_srv (server_name),
        INDEX idx_sess_user (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ad_logons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        dc_name VARCHAR(100) NOT NULL,
        username VARCHAR(255) NOT NULL,
        workstation VARCHAR(255) NOT NULL,
        ip_address VARCHAR(100) DEFAULT '',
        logon_type INT NOT NULL,
        event_type VARCHAR(50) NOT NULL,
        action_label VARCHAR(100) NOT NULL,
        INDEX idx_logon_ts (timestamp),
        INDEX idx_logon_user (username),
        INDEX idx_logon_ws (workstation)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_schedules (
        username VARCHAR(100) PRIMARY KEY,
        schedule_type VARCHAR(20) NOT NULL DEFAULT 'mon_fri',
        work_start TIME NOT NULL DEFAULT '08:30:00',
        work_end TIME NOT NULL DEFAULT '17:30:00',
        work_days VARCHAR(50) NOT NULL DEFAULT '1,2,3,4,5',
        ref_date DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS schedule_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) UNIQUE NOT NULL,
        schedule_type VARCHAR(20) NOT NULL DEFAULT 'mon_fri',
        work_start TIME NOT NULL DEFAULT '08:30:00',
        work_end TIME NOT NULL DEFAULT '17:30:00',
        work_days VARCHAR(50) NOT NULL DEFAULT '1,2,3,4,5',
        ref_date DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ((int)$pdo->query("SELECT COUNT(*) FROM schedule_templates")->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO schedule_templates (name, schedule_type, work_start, work_end, work_days, ref_date) VALUES
            ('Стандартний (Пн-Пт)', 'mon_fri', '08:30:00', '17:30:00', '1,2,3,4,5', NULL),
            ('Змінний (Доба через три / 24-72)', 'shift_24', '00:00:00', '00:00:00', '1,2,3,4,5', '2026-06-01'),
            ('Змінний (День-Ніч-48 / 12-12-48)', 'shift_12', '00:00:00', '00:00:00', '1,2,3,4,5', '2026-06-01'),
            ('Всі години позаробочі (Always Off)', 'always_off', '00:00:00', '00:00:00', '', NULL)
        ");
    }

    // Dynamic update for existing default template
    $pdo->exec("UPDATE schedule_templates SET work_end = '17:30:00' WHERE name = 'Стандартний (Пн-Пт)' AND work_end = '17:00:00'");

    try {
        $pdo->query("SELECT template_id FROM user_schedules LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE user_schedules ADD COLUMN template_id INT DEFAULT NULL");
    }

    // Add show_rdp_actions column if it does not exist
    try {
        $pdo->query("SELECT show_rdp_actions FROM users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN show_rdp_actions TINYINT NOT NULL DEFAULT 0");
    }
}
