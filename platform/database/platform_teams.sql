-- ============================================================
-- RetailCore - Marketing leads and platform employees
--
-- The two modules the Marketing & HR role owns. Finance needs no
-- table of its own: subscriptions, billing and revenue already
-- live in company_subscriptions, subscription_history and
-- subscription_plans.
--
-- Safe to re-run: IF NOT EXISTS on both tables, no seed.
-- ============================================================

SET NAMES utf8mb4;


-- ------------------------------------------------------------
-- MARKETING: LEADS
-- ------------------------------------------------------------

-- A business that has shown interest but is not a tenant yet.
--
-- company_id is filled in only when a lead converts, which is what
-- makes "how many of our leads became customers" answerable at all.
-- It stays null for everyone still in the pipeline.
CREATE TABLE IF NOT EXISTS marketing_leads (
    lead_id       INT(11) NOT NULL AUTO_INCREMENT,
    business_name VARCHAR(190) NOT NULL,
    contact_name  VARCHAR(150) NOT NULL,
    contact_email VARCHAR(150) NOT NULL,
    contact_phone VARCHAR(60) NULL,
    city          VARCHAR(120) NULL,
    branches      INT(11) NOT NULL DEFAULT 1,
    source        ENUM('Website','Referral','Walk-in','Phone','Event','Social Media','Other')
                  NOT NULL DEFAULT 'Website',
    interest      ENUM('Retail Starter','Retail Professional','Retail Enterprise','Not Sure')
                  NOT NULL DEFAULT 'Not Sure',
    stage         ENUM('New','Contacted','Demo Booked','Proposal Sent','Won','Lost')
                  NOT NULL DEFAULT 'New',
    owner_id      INT(11) NULL,
    company_id    INT(11) NULL,
    lost_reason   VARCHAR(255) NULL,
    notes         TEXT NULL,
    next_action   DATE NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (lead_id),
    KEY stage (stage),
    KEY owner_id (owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ------------------------------------------------------------
-- HR: PLATFORM EMPLOYEES
-- ------------------------------------------------------------

-- RetailCore's own staff.
--
-- Kept apart from the employees table, which belongs to tenants: a
-- row there is scoped to a company and a branch, and our own people
-- have neither. Mixing them would put RetailCore staff inside a
-- customer's HR screens.
--
-- user_id links an employee to their login when they have one. Not
-- everyone does, and a login can be revoked without erasing the
-- employment record, so the column is nullable.
CREATE TABLE IF NOT EXISTS platform_employees (
    employee_id     INT(11) NOT NULL AUTO_INCREMENT,
    employee_code   VARCHAR(20) NOT NULL,
    full_name       VARCHAR(150) NOT NULL,
    work_email      VARCHAR(150) NOT NULL,
    contact_number  VARCHAR(60) NULL,
    department      ENUM('Engineering','Marketing','Sales','Support','Finance','People','Operations')
                    NOT NULL DEFAULT 'Operations',
    position        VARCHAR(120) NOT NULL,
    employment_type ENUM('Full-time','Part-time','Contract','Intern')
                    NOT NULL DEFAULT 'Full-time',
    status          ENUM('Active','On Leave','Resigned','Terminated')
                    NOT NULL DEFAULT 'Active',
    date_hired      DATE NOT NULL,
    date_left       DATE NULL,
    user_id         INT(11) NULL,
    notes           TEXT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (employee_id),
    UNIQUE KEY employee_code (employee_code),
    KEY department (department),
    KEY status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
