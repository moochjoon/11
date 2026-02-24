-- Namak Database Schema
-- Create all required tables for the messaging application

-- Users Table
CREATE TABLE IF NOT EXISTS `nm_users` (
                                          `id` INT AUTO_INCREMENT PRIMARY KEY,
                                          `username` VARCHAR(255) UNIQUE NOT NULL,
    `email` VARCHAR(255) UNIQUE,
    `phone` VARCHAR(20) UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `avatar` VARCHAR(255),
    `bio` TEXT,
    `status` ENUM('active', 'inactive', 'banned') DEFAULT 'active',
    `last_login` DATETIME,
    `last_seen` DATETIME,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_username` (`username`),
    INDEX `idx_email` (`email`),
    INDEX `idx_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chats Table
CREATE TABLE IF NOT EXISTS `nm_chats` (
                                          `id` INT AUTO_INCREMENT PRIMARY KEY,
                                          `type` ENUM('direct', 'group') DEFAULT 'direct',
    `name` VARCHAR(255),
    `description` TEXT,
    `avatar` VARCHAR(255),
    `created_by` INT,
    `last_message_id` INT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `nm_users`(`id`),
    INDEX `idx_type` (`type`),
    INDEX `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chat Members Table
CREATE TABLE IF NOT EXISTS `nm_chat_members` (
                                                 `id` INT AUTO_INCREMENT PRIMARY KEY,
                                                 `chat_id` INT NOT NULL,
                                                 `user_id` INT NOT NULL,
                                                 `role` ENUM('admin', 'moderator', 'member') DEFAULT 'member',
    `archived` TINYINT DEFAULT 0,
    `joined_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `left_at` DATETIME,
    FOREIGN KEY (`chat_id`) REFERENCES `nm_chats`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `nm_users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_member` (`chat_id`, `user_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_archived` (`archived`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messages Table
CREATE TABLE IF NOT EXISTS `nm_messages` (
                                             `id` INT AUTO_INCREMENT PRIMARY KEY,
                                             `chat_id` INT NOT NULL,
                                             `from_user_id` INT NOT NULL,
                                             `to_user_id` INT,
                                             `content` LONGTEXT,
                                             `type` ENUM('text', 'image', 'video', 'audio', 'file') DEFAULT 'text',
    `status` ENUM('sending', 'delivered', 'read') DEFAULT 'delivering',
    `read_at` DATETIME,
    `pinned` TINYINT DEFAULT 0,
    `pinned_at` DATETIME,
    `ephemeral` TINYINT DEFAULT 0,
    `encrypted` TINYINT DEFAULT 0,
    `edited_at` DATETIME,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`chat_id`) REFERENCES `nm_chats`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`from_user_id`) REFERENCES `nm_users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`to_user_id`) REFERENCES `nm_users`(`id`) ON DELETE SET NULL,
    INDEX `idx_chat_id` (`chat_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_pinned` (`pinned`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Media Table
CREATE TABLE IF NOT EXISTS `nm_media` (
                                          `id` INT AUTO_INCREMENT PRIMARY KEY,
                                          `message_id` INT,
                                          `chat_id` INT,
                                          `uploaded_by` INT NOT NULL,
                                          `type` ENUM('image', 'video', 'audio', 'document', 'other') DEFAULT 'other',
    `original_name` VARCHAR(255),
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `file_hash` VARCHAR(255) UNIQUE,
    `file_size` BIGINT,
    `mime_type` VARCHAR(100),
    `width` INT,
    `height` INT,
    `duration` INT,
    `thumbnail_path` VARCHAR(255),
    `download_count` INT DEFAULT 0,
    `temporary` TINYINT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`message_id`) REFERENCES `nm_messages`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`chat_id`) REFERENCES `nm_chats`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`uploaded_by`) REFERENCES `nm_users`(`id`) ON DELETE CASCADE,
    INDEX `idx_uploaded_by` (`uploaded_by`),
    INDEX `idx_type` (`type`),
    INDEX `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contacts Table
CREATE TABLE IF NOT EXISTS `nm_contacts` (
                                             `id` INT AUTO_INCREMENT PRIMARY KEY,
                                             `user_id` INT NOT NULL,
                                             `contact_user_id` INT NOT NULL,
                                             `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                                             FOREIGN KEY (`user_id`) REFERENCES `nm_users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`contact_user_id`) REFERENCES `nm_users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_contact` (`user_id`, `contact_user_id`),
    INDEX `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Blocked Users Table
CREATE TABLE IF NOT EXISTS `nm_blocked_users` (
                                                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                                                  `user_id` INT NOT NULL,
                                                  `blocked_user_id` INT NOT NULL,
                                                  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                                                  FOREIGN KEY (`user_id`) REFERENCES `nm_users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`blocked_user_id`) REFERENCES `nm_users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_blocked` (`user_id`, `blocked_user_id`),
    INDEX `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Message Reactions Table
CREATE TABLE IF NOT EXISTS `nm_message_reactions` (
                                                      `id` INT AUTO_INCREMENT PRIMARY KEY,
                                                      `message_id` INT NOT NULL,
                                                      `user_id` INT NOT NULL,
                                                      `emoji` VARCHAR(10),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`message_id`) REFERENCES `nm_messages`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `nm_users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_reaction` (`message_id`, `user_id`, `emoji`),
    INDEX `idx_message_id` (`message_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login Attempts Table
CREATE TABLE IF NOT EXISTS `nm_login_attempts` (
                                                   `id` INT AUTO_INCREMENT PRIMARY KEY,
                                                   `user_id` INT,
                                                   `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `success` TINYINT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `nm_users`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_ip_address` (`ip_address`),
    INDEX `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity Log Table
CREATE TABLE IF NOT EXISTS `nm_activity_logs` (
                                                  `id` INT AUTO_INCREMENT PRIMARY KEY,
                                                  `user_id` INT,
                                                  `action` VARCHAR(255),
    `description` TEXT,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `nm_users`(`id`) ON DELETE SET NULL,
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_action` (`action`),
    INDEX `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create Indexes for Performance
CREATE INDEX `idx_nm_messages_chat_from` ON `nm_messages` (`chat_id`, `from_user_id`);
CREATE INDEX `idx_nm_messages_read` ON `nm_messages` (`chat_id`, `status`, `read_at`);
CREATE INDEX `idx_nm_chat_members_user_chat` ON `nm_chat_members` (`user_id`, `chat_id`);
