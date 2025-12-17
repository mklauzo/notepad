Aplikacja do robienia notatek, można dodawać załączniki, wklejać zrzuty obrazu etc.
<br>Hasło dla domyślnego użytkownika admin to: admin123#

Tworzenie bazy danych (część tabel uzupełnia plik index.php):
```
CREATE DATABASE IF NOT EXISTS `notatki_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `notatki_db`;

-- Tabela dla notatek
CREATE TABLE IF NOT EXISTS `notes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `unique_id` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Unikalny ID notatki (jak w oryginalnym uid())',
    `title` VARCHAR(255) NOT NULL,
    `content` MEDIUMTEXT,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_unique_id` (`unique_id`),
    INDEX `idx_title` (`title`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela dla załączników
CREATE TABLE IF NOT EXISTS `attachments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `note_unique_id` VARCHAR(50) NOT NULL COMMENT 'Powiązanie z notatką',
    `file_name` VARCHAR(255) NOT NULL,
    `file_path` VARCHAR(512) NOT NULL COMMENT 'Ścieżka do pliku na serwerze',
    `mime_type` VARCHAR(100),
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_note_file` (`note_unique_id`, `file_name`),
    CONSTRAINT `fk_note_unique_id`
        FOREIGN KEY (`note_unique_id`)
        REFERENCES `notes`(`unique_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 1. Tworzymy tabelę użytkowników  Hasło dla domyślnego użytkownika admin to: admin123#
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'user') NOT NULL DEFAULT 'user',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Dodajemy domyślnego administratora (Login: admin, Hasło: admin123)
-- Hash hasła wygenerowany funkcją password_hash()
INSERT INTO `users` (`username`, `password`, `role`) 
VALUES ('admin', '$2y$10$8.u.1s.1s.1s.1s.1s.1s.1s.1s.1s.1s.1s.1s.1s.1s.1s.1s', 'admin')
ON DUPLICATE KEY UPDATE `username`=`username`;

-- 3. Dodajemy kolumnę user_id do notatek (jeśli nie istnieje)
-- Jeśli masz już notatki, przypisujemy je do admina (id=1), żeby nie zniknęły
ALTER TABLE `notes` ADD COLUMN `user_id` INT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE `notes` ADD INDEX `idx_user_id` (`user_id`);
```

