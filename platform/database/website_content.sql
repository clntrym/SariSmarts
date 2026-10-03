-- ============================================================
-- RetailSync - website content tables
--
-- Makes the public marketing pages (index, features, pricing)
-- editable from the Super Admin, the same way platform.php
-- already reads website_platform_* and website_modules.
--
-- Safe to re-run: every table uses IF NOT EXISTS and every seed
-- is an idempotent INSERT ... ON DUPLICATE KEY UPDATE.
-- ============================================================

SET NAMES utf8mb4;


-- ------------------------------------------------------------
-- HERO
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS website_hero (
    hero_id           INT(11) NOT NULL AUTO_INCREMENT,
    badge             VARCHAR(150) NOT NULL,
    title             VARCHAR(255) NOT NULL,
    description       TEXT NOT NULL,
    primary_btn_text  VARCHAR(60) NOT NULL DEFAULT 'Start Free Trial',
    primary_btn_link  VARCHAR(255) NOT NULL DEFAULT 'pricing.php',
    secondary_btn_text VARCHAR(60) NOT NULL DEFAULT 'Book a Demo',
    secondary_btn_link VARCHAR(255) NOT NULL DEFAULT 'bookDemo.php',
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                      ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (hero_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Trust badges under the hero. These replace the invented
-- "1,200+ branches / 18M+ transactions" figures, which claimed
-- traction the product does not have yet.
CREATE TABLE IF NOT EXISTS website_hero_badges (
    badge_id    INT(11) NOT NULL AUTO_INCREMENT,
    badge_order INT(11) NOT NULL,
    icon        VARCHAR(100) NOT NULL,
    label       VARCHAR(120) NOT NULL,
    status      ENUM('Active','Inactive') DEFAULT 'Active',
    PRIMARY KEY (badge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ------------------------------------------------------------
-- WHY SARISMART
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS website_why_section (
    section_id  INT(11) NOT NULL AUTO_INCREMENT,
    badge       VARCHAR(120) NOT NULL,
    title       VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS website_why_cards (
    card_id    INT(11) NOT NULL AUTO_INCREMENT,
    card_order INT(11) NOT NULL,
    icon       VARCHAR(100) NOT NULL,
    title      VARCHAR(150) NOT NULL,
    status     ENUM('Active','Inactive') DEFAULT 'Active',
    PRIMARY KEY (card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ------------------------------------------------------------
-- UNIQUE SELLING POINT
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS website_usp_section (
    section_id  INT(11) NOT NULL AUTO_INCREMENT,
    badge       VARCHAR(120) NOT NULL,
    title       VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ------------------------------------------------------------
-- WHAT'S INCLUDED
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS website_included_section (
    section_id  INT(11) NOT NULL AUTO_INCREMENT,
    badge       VARCHAR(120) NOT NULL,
    title       VARCHAR(255) NOT NULL,
    description TEXT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS website_included_cards (
    card_id     INT(11) NOT NULL AUTO_INCREMENT,
    card_order  INT(11) NOT NULL,
    icon        VARCHAR(100) NOT NULL,
    icon_bg     VARCHAR(60) NOT NULL DEFAULT 'bg-sky-100',
    icon_color  VARCHAR(60) NOT NULL DEFAULT 'text-sky-600',
    title       VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    status      ENUM('Active','Inactive') DEFAULT 'Active',
    PRIMARY KEY (card_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS website_included_features (
    feature_id    INT(11) NOT NULL AUTO_INCREMENT,
    card_id       INT(11) NOT NULL,
    feature_order INT(11) NOT NULL,
    feature_name  VARCHAR(150) NOT NULL,
    PRIMARY KEY (feature_id),
    KEY card_id (card_id),
    CONSTRAINT fk_included_features_card
        FOREIGN KEY (card_id) REFERENCES website_included_cards (card_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ------------------------------------------------------------
-- PLANS - extra presentation fields + the user-role list
-- ------------------------------------------------------------

-- tagline  = "For Small Convenience Stores"
-- price_label = shown instead of a number, e.g. "Custom Pricing"
-- inherits_text = "Includes everything in Starter PLUS:"
ALTER TABLE subscription_plans
    ADD COLUMN IF NOT EXISTS tagline VARCHAR(150) NULL AFTER plan_name,
    ADD COLUMN IF NOT EXISTS price_label VARCHAR(60) NULL AFTER yearly_price,
    ADD COLUMN IF NOT EXISTS inherits_text VARCHAR(150) NULL AFTER description,
    ADD COLUMN IF NOT EXISTS plan_order INT(11) NOT NULL DEFAULT 0 AFTER status;

CREATE TABLE IF NOT EXISTS subscription_plan_roles (
    role_id    INT(11) NOT NULL AUTO_INCREMENT,
    plan_id    INT(11) NOT NULL,
    role_order INT(11) NOT NULL,
    role_name  VARCHAR(100) NOT NULL,
    PRIMARY KEY (role_id),
    KEY plan_id (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Keep the ordering of feature rows stable on the pricing page.
ALTER TABLE subscription_plan_features
    ADD COLUMN IF NOT EXISTS feature_order INT(11) NOT NULL DEFAULT 0;
