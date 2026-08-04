-- security_monitor データベースのテーブル構造
-- ※実際のデータ（攻撃ログの中身など）は含まれていません。テーブルの設計のみです。

CREATE DATABASE IF NOT EXISTS security_monitor DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
USE security_monitor;

-- SSHへのログイン試行ログ
CREATE TABLE `ssh_attack_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `event_time` datetime NOT NULL,
  `status` enum('SUCCESS','FAILED') NOT NULL,
  `username` varchar(100) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `country_code` varchar(5) DEFAULT NULL,
  `organization` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_event_time` (`event_time`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_username` (`username`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Webサーバーへの不審なアクセスログ
CREATE TABLE `web_attack_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_time` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `method` varchar(20) DEFAULT NULL,
  `path` varchar(255) DEFAULT NULL,
  `status_code` int(11) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `country_code` varchar(10) DEFAULT NULL,
  `organization` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ダッシュボードへのログイン履歴
CREATE TABLE `login_history` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `login_status` enum('SUCCESS','FAILED') NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `login_time` datetime NOT NULL,
  `country_code` varchar(5) DEFAULT NULL,
  `organization` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_time` (`login_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ダッシュボードの各種設定（2段階認証のシークレットなど、値そのものはここには入りません）
CREATE TABLE `dashboard_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
