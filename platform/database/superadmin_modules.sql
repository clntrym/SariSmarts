-- ============================================================
-- SariSmart - Marketplace and Customer Support modules
--
-- The Super Admin sidebar has linked Marketplace, Billing and
-- Customer Support for a while. Marketplace was a two line stub
-- and the other two were 404s, because none of them had anywhere
-- to read from.
--
-- Billing needs no table: it reads company_subscriptions,
-- subscription_plans, company and subscription_history, which
-- already hold every figure it shows. Only these two do.
--
-- Safe to re-run: IF NOT EXISTS on every table, and each seed
-- only fires while its table is still empty.
-- ============================================================

SET NAMES utf8mb4;


-- ------------------------------------------------------------
-- MARKETPLACE
-- ------------------------------------------------------------

-- Paid add-ons sold on top of a subscription. pricing.php already
-- tells customers "Marketplace apps are billed separately per
-- branch", so the price is per branch per cycle.
CREATE TABLE IF NOT EXISTS marketplace_apps (
    app_id            INT(11) NOT NULL AUTO_INCREMENT,
    app_order         INT(11) NOT NULL DEFAULT 1,
    app_name          VARCHAR(150) NOT NULL,
    category          VARCHAR(80) NOT NULL DEFAULT 'Operations',
    short_description VARCHAR(255) NOT NULL,
    description       TEXT NULL,
    icon              VARCHAR(100) NOT NULL DEFAULT 'bi bi-puzzle',
    icon_bg           VARCHAR(60) NOT NULL DEFAULT 'bg-sky-100',
    icon_color        VARCHAR(60) NOT NULL DEFAULT 'text-sky-600',
    price_per_branch  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    billing_cycle     ENUM('Monthly','Yearly','One-time') NOT NULL DEFAULT 'Monthly',
    status            ENUM('Active','Inactive') NOT NULL DEFAULT 'Inactive',
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                      ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (app_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ------------------------------------------------------------
-- CUSTOMER SUPPORT
-- ------------------------------------------------------------

-- company_id is nullable on purpose: a prospect can call before
-- their registration is approved, and the ticket still has to go
-- somewhere.
CREATE TABLE IF NOT EXISTS support_tickets (
    ticket_id     INT(11) NOT NULL AUTO_INCREMENT,
    ticket_code   VARCHAR(20) NOT NULL,
    company_id    INT(11) NULL,
    contact_name  VARCHAR(150) NOT NULL,
    contact_email VARCHAR(150) NOT NULL,
    subject       VARCHAR(190) NOT NULL,
    message       TEXT NOT NULL,
    category      ENUM('Billing','Technical','Account','Feature Request','Other')
                  NOT NULL DEFAULT 'Other',
    priority      ENUM('Low','Normal','High','Urgent') NOT NULL DEFAULT 'Normal',
    status        ENUM('Open','In Progress','Waiting on Customer','Resolved','Closed')
                  NOT NULL DEFAULT 'Open',
    assigned_to   INT(11) NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                  ON UPDATE CURRENT_TIMESTAMP,
    resolved_at   DATETIME NULL,
    PRIMARY KEY (ticket_id),
    UNIQUE KEY ticket_code (ticket_code),
    KEY company_id (company_id),
    KEY status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- One row per message in the thread. is_internal marks a note the
-- operator leaves for colleagues rather than a reply to the
-- customer, so the two can never be mixed up on screen.
CREATE TABLE IF NOT EXISTS support_ticket_replies (
    reply_id    INT(11) NOT NULL AUTO_INCREMENT,
    ticket_id   INT(11) NOT NULL,
    author_id   INT(11) NULL,
    author_name VARCHAR(150) NOT NULL,
    body        TEXT NOT NULL,
    is_internal TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (reply_id),
    KEY ticket_id (ticket_id),
    CONSTRAINT support_ticket_replies_ticket
        FOREIGN KEY (ticket_id) REFERENCES support_tickets (ticket_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============================================================
-- SEED
--
-- Marketplace gets a starting catalogue so the module has
-- something to show. Every price is 0.00 and every app is
-- Inactive: these are placeholders for real commercial terms,
-- not offers, and nothing reaches a customer until an operator
-- sets a price and switches the app to Active.
--
-- Support is deliberately left empty. A seeded ticket would be a
-- made up customer request sitting in a real queue; the module
-- shows its empty state instead and staff log the first one.
-- ============================================================

INSERT INTO marketplace_apps
    (app_order, app_name, category, short_description, icon, icon_bg, icon_color)
SELECT * FROM (
    SELECT 1 AS a, 'Advanced Analytics' AS b, 'Insights' AS c,
           'Forecasting and trend reports on top of the standard dashboards.' AS d,
           'bi bi-graph-up-arrow' AS e, 'bg-sky-100' AS f, 'text-sky-600' AS g
    UNION ALL SELECT 2, 'Customer Loyalty', 'Sales',
           'Points, tiers and reward redemption at the point of sale.',
           'bi bi-award', 'bg-amber-100', 'text-amber-600'
    UNION ALL SELECT 3, 'SMS Notifications', 'Operations',
           'Low stock alerts, shift reminders and receipts by text message.',
           'bi bi-chat-dots', 'bg-emerald-100', 'text-emerald-600'
    UNION ALL SELECT 4, 'E-Commerce Sync', 'Sales',
           'Keep an online storefront in step with branch inventory.',
           'bi bi-bag-check', 'bg-violet-100', 'text-violet-600'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM marketplace_apps);
