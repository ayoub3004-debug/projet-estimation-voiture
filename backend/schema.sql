-- ============================================================
-- Cote Réelle — Schéma de base de données
-- Import : mysql -u root -p cotereelle < schema.sql
-- (crée d'abord la base : CREATE DATABASE cotereelle CHARACTER SET utf8mb4;)
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS estimations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    vehicule VARCHAR(190) DEFAULT '',
    prix_achat DECIMAL(10,2) DEFAULT NULL,
    estimation DECIMAL(10,2) DEFAULT NULL,
    marge_nette DECIMAL(10,2) DEFAULT NULL,
    verdict VARCHAR(60) DEFAULT '',
    payload_json TEXT DEFAULT NULL, -- snapshot complet (véhicule, comparables, réglages) pour ré-ouvrir l'estimation plus tard
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_estimations_user ON estimations(user_id, created_at DESC);
