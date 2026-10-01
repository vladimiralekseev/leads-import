-- Структура БД для імпорту заявок
-- mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS leads_import
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE leads_import;

-- Сесії імпорту: один завантажений файл = один запис.
-- Зберігає стан, щоб обробку можна було продовжити наступним HTTP-запитом.
CREATE TABLE IF NOT EXISTS imports (
    id              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    original_name   VARCHAR(255)     NOT NULL,
    file_path       VARCHAR(500)     NOT NULL,
    file_size       BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    status          ENUM('uploading','queued','processing','done','failed') NOT NULL DEFAULT 'uploading',
    total_rows      INT UNSIGNED     NOT NULL DEFAULT 0  COMMENT 'Рядків даних у файлі (без заголовка)',
    processed_rows  INT UNSIGNED     NOT NULL DEFAULT 0  COMMENT 'Скільки рядків файлу вже пройдено (курсор)',
    imported_rows   INT UNSIGNED     NOT NULL DEFAULT 0  COMMENT 'Записано/оновлено в leads',
    failed_rows     INT UNSIGNED     NOT NULL DEFAULT 0  COMMENT 'Пропущено через помилки валідації',
    steps           INT UNSIGNED     NOT NULL DEFAULT 0  COMMENT 'Кількість HTTP-кроків обробки',
    error_message   TEXT             NULL,
    created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    started_at      DATETIME         NULL,
    finished_at     DATETIME         NULL,
    PRIMARY KEY (id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Заявки
CREATE TABLE IF NOT EXISTS leads (
    id               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    external_id      VARCHAR(64)    NOT NULL,
    created_at       DATETIME       NULL,
    first_name       VARCHAR(100)   NULL,
    last_name        VARCHAR(100)   NULL,
    phone            VARCHAR(32)    NULL,
    email            VARCHAR(255)   NULL,
    city             VARCHAR(100)   NULL,
    source           VARCHAR(100)   NULL,
    utm_campaign     VARCHAR(100)   NULL,
    product          VARCHAR(255)   NULL,
    budget_uah       DECIMAL(12,2)  NULL,
    status           VARCHAR(32)    NULL,
    manager          VARCHAR(100)   NULL,
    comment          TEXT           NULL,
    next_contact_at  DATETIME       NULL,
    import_id        INT UNSIGNED   NULL COMMENT 'Останній імпорт, що записав/оновив рядок',
    imported_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_external_id (external_id),
    KEY idx_status (status),
    KEY idx_created_at (created_at),
    KEY idx_phone (phone),
    KEY idx_email (email),
    KEY idx_import (import_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Рядки, які не пройшли валідацію (щоб було видно, що саме пропущено)
CREATE TABLE IF NOT EXISTS import_errors (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    import_id   INT UNSIGNED  NOT NULL,
    excel_row   INT UNSIGNED  NOT NULL COMMENT 'Номер рядка в Excel',
    message     VARCHAR(500)  NOT NULL,
    PRIMARY KEY (id),
    KEY idx_import (import_id),
    CONSTRAINT fk_import_errors_import FOREIGN KEY (import_id) REFERENCES imports (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
