SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS admins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS server_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    token_encrypted TEXT NOT NULL,
    revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_server_groups_name (name),
    UNIQUE KEY uq_server_groups_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nodes (
    id CHAR(36) NOT NULL,
    group_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    hostname VARCHAR(255) NOT NULL DEFAULT '',
    remote_ip VARCHAR(45) NOT NULL DEFAULT '',
    os_name VARCHAR(100) NOT NULL DEFAULT '',
    architecture VARCHAR(32) NOT NULL DEFAULT '',
    agent_version VARCHAR(32) NOT NULL DEFAULT '',
    secret_hash CHAR(64) NOT NULL,
    applied_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(2000) NOT NULL DEFAULT '',
    last_seen_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_nodes_group (group_id),
    KEY idx_nodes_last_seen (last_seen_at),
    CONSTRAINT fk_nodes_group FOREIGN KEY (group_id) REFERENCES server_groups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forward_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    listen_port SMALLINT UNSIGNED NOT NULL,
    target_ipv4 VARCHAR(15) NOT NULL,
    target_port SMALLINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_forward_group_port (group_id, listen_port),
    KEY idx_forward_group_enabled (group_id, enabled),
    CONSTRAINT fk_forward_group FOREIGN KEY (group_id) REFERENCES server_groups (id) ON DELETE CASCADE,
    CONSTRAINT chk_forward_listen_port CHECK (listen_port BETWEEN 1 AND 65535),
    CONSTRAINT chk_forward_target_port CHECK (target_port BETWEEN 1 AND 65535)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
