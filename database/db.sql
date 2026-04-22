-- Home Chore Management System (ChoreQuest)
-- Full Database Schema

CREATE DATABASE IF NOT EXISTS `chore_system`;
USE `chore_system`;

-- ═══════════════════════════════════════════════════
-- Parents table
-- ═══════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `parents` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(150) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ═══════════════════════════════════════════════════
-- Kids table (linked to a parent)
-- Added: date_of_birth, age_group (computed), savings_balance
-- ═══════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `kids` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `parent_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `date_of_birth` DATE NOT NULL,
    `points` INT DEFAULT 0,
    `savings_balance` DECIMAL(10,2) DEFAULT 0.00,
    `current_streak` INT DEFAULT 0,
    `longest_streak` INT DEFAULT 0,
    `last_chore_date` DATE DEFAULT NULL,
    `target_reward_id` INT DEFAULT NULL,
    `avatar` VARCHAR(10) DEFAULT '🦸',
    `pin` VARCHAR(4) DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`parent_id`) REFERENCES `parents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ═══════════════════════════════════════════════════
-- Chores table (assigned to a kid by a parent)
-- Updated: status ENUM with pending_review & rejected, proof_photo
-- ═══════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `chores` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `parent_id` INT NOT NULL,
    `kid_id` INT NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT,
    `points` INT DEFAULT 10,
    `status` ENUM('assigned', 'pending_review', 'completed', 'rejected') DEFAULT 'assigned',
    `proof_photo` VARCHAR(255) DEFAULT NULL,
    `rejection_note` TEXT DEFAULT NULL,
    `due_date` DATE DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (`parent_id`) REFERENCES `parents`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`kid_id`) REFERENCES `kids`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ═══════════════════════════════════════════════════
-- Rewards table (parent-defined rewards kids can redeem)
-- ═══════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `rewards` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `parent_id` INT NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT,
    `points_cost` INT NOT NULL,
    `age_group` ENUM('young', 'teen', 'all') DEFAULT 'all',
    `emoji` VARCHAR(10) DEFAULT '🎁',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`parent_id`) REFERENCES `parents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ═══════════════════════════════════════════════════
-- Reward Redemptions (log of kids redeeming rewards)
-- ═══════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `reward_redemptions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `kid_id` INT NOT NULL,
    `reward_id` INT NOT NULL,
    `points_spent` INT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`kid_id`) REFERENCES `kids`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`reward_id`) REFERENCES `rewards`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ═══════════════════════════════════════════════════
-- Transactions table (pocket money audit trail)
-- ═══════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `kid_id` INT NOT NULL,
    `type` ENUM('chore_earning', 'reward_redemption', 'savings_deposit', 'savings_withdrawal', 'manual_adjustment') NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `description` VARCHAR(255),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`kid_id`) REFERENCES `kids`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ═══════════════════════════════════════════════════
-- Savings Goals (lock-in periods for teen group)
-- ═══════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `savings_goals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `kid_id` INT NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `lock_months` INT NOT NULL COMMENT '3, 6, or 9',
    `locked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `unlocks_at` TIMESTAMP NOT NULL,
    `status` ENUM('locked', 'unlocked', 'withdrawn') DEFAULT 'locked',
    FOREIGN KEY (`kid_id`) REFERENCES `kids`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ═══════════════════════════════════════════════════
-- Migration helpers: If upgrading from old schema, run these:
-- ═══════════════════════════════════════════════════
-- ALTER TABLE `kids`
--   ADD COLUMN `date_of_birth` DATE NOT NULL DEFAULT '2015-01-01' AFTER `password`,
--   ADD COLUMN `savings_balance` DECIMAL(10,2) DEFAULT 0.00 AFTER `points`;
--
-- ALTER TABLE `chores`
--   MODIFY COLUMN `status` ENUM('assigned','pending_review','completed','rejected') DEFAULT 'assigned',
--   ADD COLUMN `proof_photo` VARCHAR(255) DEFAULT NULL AFTER `status`,
--   ADD COLUMN `rejection_note` TEXT DEFAULT NULL AFTER `proof_photo`;
