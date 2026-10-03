-- ============================================================
-- SariSmart - Notifications, Media, Audit and Settings
--
-- The last four Super Admin sidebar links that went nowhere.
-- Reports needs no table of its own: it reads what the other
-- modules already store.
--
-- Safe to re-run: IF NOT EXISTS on every table, and the settings
-- seed only fires while the table is still empty.
-- ============================================================

SET NAMES utf8mb4;


-- ------------------------------------------------------------
-- NOTIFICATIONS
-- ------------------------------------------------------------

-- Announcements the platform sends to tenants. audience decides
-- which of company_id and plan_id matters; both stay null for an
-- announcement that goes to everyone.
CREATE TABLE IF NOT EXISTS platform_notifications (
    notification_id INT(11) NOT NULL AUTO_INCREMENT,
    title           VARCHAR(190) NOT NULL,
    body            TEXT NOT NULL,
    audience        ENUM('All Companies','Single Company','By Plan')
                    NOT NULL DEFAULT 'All Companies',
    company_id      INT(11) NULL,
    plan_id         INT(11) NULL,
    severity        ENUM('Info','Warning','Critical') NOT NULL DEFAULT 'Info',
    status          ENUM('Draft','Published','Archived') NOT NULL DEFAULT 'Draft',
    publish_at      DATETIME NULL,
    expires_at      DATETIME NULL,
    created_by      INT(11) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id),
    KEY status (status),
    KEY audience (audience)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ------------------------------------------------------------
-- MEDIA LIBRARY
-- ------------------------------------------------------------

-- file_name is what sits on disk under uploads/media, and it is
-- generated rather than taken from the upload. original_name is
-- only ever shown, never used to build a path.
CREATE TABLE IF NOT EXISTS media_files (
    media_id      INT(11) NOT NULL AUTO_INCREMENT,
    file_name     VARCHAR(190) NOT NULL,
    original_name VARCHAR(190) NOT NULL,
    mime_type     VARCHAR(100) NOT NULL,
    file_size     INT(11) NOT NULL DEFAULT 0,
    category      VARCHAR(60) NOT NULL DEFAULT 'General',
    caption       VARCHAR(255) NULL,
    uploaded_by   INT(11) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (media_id),
    UNIQUE KEY file_name (file_name),
    KEY category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ------------------------------------------------------------
-- AUDIT LOG
-- ------------------------------------------------------------

-- user_name is stored alongside user_id on purpose. The whole
-- point of the log is that it still reads correctly after the
-- account that did the thing has been renamed or removed.
CREATE TABLE IF NOT EXISTS audit_log (
    log_id     INT(11) NOT NULL AUTO_INCREMENT,
    user_id    INT(11) NULL,
    user_name  VARCHAR(150) NOT NULL DEFAULT 'System',
    action     VARCHAR(100) NOT NULL,
    entity     VARCHAR(60) NOT NULL,
    entity_id  VARCHAR(60) NULL,
    summary    TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (log_id),
    KEY entity (entity),
    KEY created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ------------------------------------------------------------
-- SETTINGS
-- ------------------------------------------------------------

-- One row. A key/value table would be more flexible, but every
-- setting here has a type and a validation rule, and columns let
-- the database hold both.
CREATE TABLE IF NOT EXISTS platform_settings (
    setting_id           INT(11) NOT NULL AUTO_INCREMENT,
    platform_name        VARCHAR(120) NOT NULL DEFAULT 'SariSmart',
    support_email        VARCHAR(150) NOT NULL DEFAULT 'hello@sarismart.ph',
    support_phone        VARCHAR(60) NOT NULL DEFAULT '',
    registration_open    TINYINT(1) NOT NULL DEFAULT 1,
    maintenance_mode     TINYINT(1) NOT NULL DEFAULT 0,
    maintenance_message  TEXT NULL,
    default_plan_id      INT(11) NULL,
    trial_days           INT(11) NOT NULL DEFAULT 14,
    records_per_page     INT(11) NOT NULL DEFAULT 25,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                         ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


INSERT INTO platform_settings
    (setting_id, platform_name, support_email, support_phone,
     registration_open, maintenance_mode, maintenance_message,
     trial_days, records_per_page)
SELECT
    1,
    'SariSmart',
    'hello@sarismart.ph',
    '+63 2 8123 4567',
    1,
    0,
    'We are carrying out scheduled maintenance. Please try again shortly.',
    14,
    25
WHERE NOT EXISTS (SELECT 1 FROM platform_settings);
