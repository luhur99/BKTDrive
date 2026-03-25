-- ============================================================
-- LuhurWorkspace — Database Schema
-- Jalankan di phpMyAdmin atau MySQL CLI
-- ============================================================

CREATE DATABASE IF NOT EXISTS luhurworkspace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE luhurworkspace;

-- Tabel pengguna
CREATE TABLE users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)  NOT NULL,
    email       VARCHAR(150)  NOT NULL UNIQUE,
    password    VARCHAR(255)  NOT NULL,
    role        ENUM('admin','member') DEFAULT 'member',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Tabel folder
CREATE TABLE folders (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255)  NOT NULL,
    owner_id    INT           NOT NULL,
    parent_id   INT           DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id)  REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabel file
CREATE TABLE files (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    original_name   VARCHAR(255)  NOT NULL,
    stored_name     VARCHAR(255)  NOT NULL UNIQUE,
    size            BIGINT        NOT NULL DEFAULT 0,
    mime_type       VARCHAR(100),
    owner_id        INT           NOT NULL,
    folder_id       INT           DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id)  REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Tabel berbagi file/folder
CREATE TABLE shares (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    resource_type   ENUM('file','folder') NOT NULL,
    resource_id     INT           NOT NULL,
    shared_by       INT           NOT NULL,
    shared_with     INT           DEFAULT NULL,  -- NULL = semua anggota tim
    permission      ENUM('view','edit') DEFAULT 'view',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shared_by)   REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_with) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_share (resource_type, resource_id, shared_with)
) ENGINE=InnoDB;
