-- ============================================================
-- RetailCore - pricing, careers and footer content tables
--
-- The plan cards on pricing.php already come from
-- subscription_plans, and the job cards on careers.php from the
-- job table. Everything else on those two pages, and the whole
-- of footer.php, was written into the markup. These tables move
-- that copy behind the Super Admin editors.
--
-- Safe to re-run: every table uses IF NOT EXISTS and every seed
-- only fires when the table is still empty, so re-running never
-- overwrites content edited in the Super Admin.
-- ============================================================

SET NAMES utf8mb4;


-- ------------------------------------------------------------
-- PRICING
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS website_pricing_section (
    section_id       INT(11) NOT NULL AUTO_INCREMENT,
    hero_badge       VARCHAR(120) NOT NULL,
    hero_title       VARCHAR(255) NOT NULL,
    hero_description TEXT NOT NULL,
    compare_badge    VARCHAR(120) NOT NULL,
    compare_title    VARCHAR(255) NOT NULL,
    compare_col1     VARCHAR(120) NOT NULL,
    compare_col2     VARCHAR(120) NOT NULL,
    compare_col3     VARCHAR(120) NOT NULL,
    compare_note     TEXT NOT NULL,
    faq_badge        VARCHAR(120) NOT NULL,
    faq_title        VARCHAR(255) NOT NULL,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- One row per line of the plan comparison table.
--
-- Each cell holds either the literal 'yes' (renders a check),
-- the literal 'no' (renders a muted dash), or free text such as
-- 'Up to 10' that is printed as-is.
CREATE TABLE IF NOT EXISTS website_pricing_compare (
    row_id        INT(11) NOT NULL AUTO_INCREMENT,
    row_order     INT(11) NOT NULL DEFAULT 1,
    feature_label VARCHAR(190) NOT NULL,
    col1_value    VARCHAR(120) NOT NULL DEFAULT 'no',
    col2_value    VARCHAR(120) NOT NULL DEFAULT 'no',
    col3_value    VARCHAR(120) NOT NULL DEFAULT 'no',
    status        ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    PRIMARY KEY (row_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE IF NOT EXISTS website_pricing_faq (
    faq_id    INT(11) NOT NULL AUTO_INCREMENT,
    faq_order INT(11) NOT NULL DEFAULT 1,
    question  VARCHAR(255) NOT NULL,
    answer    TEXT NOT NULL,
    status    ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    PRIMARY KEY (faq_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ------------------------------------------------------------
-- CAREERS
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS website_careers_section (
    section_id        INT(11) NOT NULL AUTO_INCREMENT,
    hero_badge        VARCHAR(120) NOT NULL,
    hero_title        VARCHAR(255) NOT NULL,
    hero_description  TEXT NOT NULL,
    jobs_badge        VARCHAR(120) NOT NULL,
    jobs_title        VARCHAR(255) NOT NULL,
    jobs_description  TEXT NOT NULL,
    empty_title       VARCHAR(255) NOT NULL,
    empty_description TEXT NOT NULL,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                      ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ------------------------------------------------------------
-- FOOTER
-- ------------------------------------------------------------

-- copyright_text understands {year}, which footer.php replaces
-- with the current year so the notice never goes stale.
CREATE TABLE IF NOT EXISTS website_footer (
    footer_id           INT(11) NOT NULL AUTO_INCREMENT,
    cta_badge           VARCHAR(120) NOT NULL,
    cta_title           VARCHAR(255) NOT NULL,
    cta_title_highlight VARCHAR(255) NOT NULL,
    cta_description     TEXT NOT NULL,
    cta_button_text     VARCHAR(60) NOT NULL,
    cta_button_link     VARCHAR(255) NOT NULL,
    brand_name          VARCHAR(120) NOT NULL,
    brand_description   TEXT NOT NULL,
    contact_email       VARCHAR(150) NOT NULL,
    contact_phone       VARCHAR(60) NOT NULL,
    copyright_text      VARCHAR(255) NOT NULL,
    updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (footer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE IF NOT EXISTS website_footer_columns (
    column_id    INT(11) NOT NULL AUTO_INCREMENT,
    column_order INT(11) NOT NULL DEFAULT 1,
    heading      VARCHAR(120) NOT NULL,
    status       ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    PRIMARY KEY (column_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE IF NOT EXISTS website_footer_links (
    link_id    INT(11) NOT NULL AUTO_INCREMENT,
    column_id  INT(11) NOT NULL,
    link_order INT(11) NOT NULL DEFAULT 1,
    label      VARCHAR(120) NOT NULL,
    url        VARCHAR(255) NOT NULL DEFAULT '#',
    status     ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    PRIMARY KEY (link_id),
    KEY column_id (column_id),
    CONSTRAINT website_footer_links_column
        FOREIGN KEY (column_id) REFERENCES website_footer_columns (column_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- The small print beside the copyright notice. Kept apart from
-- website_footer_links because it belongs to no column.
CREATE TABLE IF NOT EXISTS website_footer_legal_links (
    legal_id   INT(11) NOT NULL AUTO_INCREMENT,
    link_order INT(11) NOT NULL DEFAULT 1,
    label      VARCHAR(120) NOT NULL,
    url        VARCHAR(255) NOT NULL DEFAULT '#',
    status     ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    PRIMARY KEY (legal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============================================================
-- SEED - the copy that was previously in the markup.
--
-- Each block is guarded so it only runs against an empty table.
-- ============================================================

INSERT INTO website_pricing_section
    (section_id, hero_badge, hero_title, hero_description,
     compare_badge, compare_title, compare_col1, compare_col2, compare_col3,
     compare_note, faq_badge, faq_title)
SELECT
    1,
    'PRICING',
    'One Platform. Built for Every Retail Business.',
    'Whether you run one convenience store or a growing retail network, choose the plan that fits your operation.',
    'COMPARE',
    'What is included in each plan',
    'Retail Starter',
    'Retail Professional',
    'Retail Enterprise',
    'Prices in PHP, excluding VAT. Marketplace apps are billed separately per branch.',
    'FAQ',
    'Billing and rollout questions'
WHERE NOT EXISTS (SELECT 1 FROM website_pricing_section);


INSERT INTO website_pricing_compare
    (row_order, feature_label, col1_value, col2_value, col3_value)
SELECT * FROM (
    SELECT  1 AS a, 'Branches included'       AS b, '1'     AS c, 'Up to 10'  AS d, 'Unlimited'    AS e UNION ALL
    SELECT  2, 'Users',                   '10',    'Unlimited', 'Unlimited'    UNION ALL
    SELECT  3, 'Point of Sale',            'yes',  'yes',       'yes'          UNION ALL
    SELECT  4, 'Inventory & Warehouse',    'yes',  'yes',       'yes'          UNION ALL
    SELECT  5, 'Purchasing & Suppliers',   'no',   'yes',       'yes'          UNION ALL
    SELECT  6, 'Promotions Engine',        'no',   'yes',       'yes'          UNION ALL
    SELECT  7, 'HR & Attendance',          'no',   'yes',       'yes'          UNION ALL
    SELECT  8, 'Payroll & Payslips',       'no',   'yes',       'yes'          UNION ALL
    SELECT  9, 'Recruitment Portal',       'no',   'yes',       'yes'          UNION ALL
    SELECT 10, 'Accounting',               'no',   'yes',       'yes'          UNION ALL
    SELECT 11, 'Analytics & Forecasting',  'no',   'Standard',  'AI Included'  UNION ALL
    SELECT 12, 'API Access',               'no',   'no',        'yes'          UNION ALL
    SELECT 13, 'White Label',              'no',   'no',        'yes'          UNION ALL
    SELECT 14, 'Support',                  'Email','Priority',  'Dedicated AM'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM website_pricing_compare);


INSERT INTO website_pricing_faq (faq_order, question, answer)
SELECT * FROM (
    SELECT 1 AS a,
           'How long does implementation take?' AS b,
           'Most stores are fully operational within one to two weeks depending on the number of branches.' AS c
    UNION ALL SELECT 2,
           'Does the POS work without internet?',
           'Yes. Transactions continue offline and automatically sync once the connection is restored.'
    UNION ALL SELECT 3,
           'Can we start with POS only and add HR later?',
           'Yes. Additional modules can be enabled whenever your business is ready.'
    UNION ALL SELECT 4,
           'Is our business data secure?',
           'Yes. All information is encrypted and securely stored with regular backups.'
    UNION ALL SELECT 5,
           'Do you support payroll and government contributions?',
           'Yes. Payroll supports SSS, PhilHealth, Pag-IBIG and other payroll deductions.'
    UNION ALL SELECT 6,
           'What hardware do we need?',
           'RetailCore works with most barcode scanners, receipt printers, cash drawers and POS terminals.'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM website_pricing_faq);


INSERT INTO website_careers_section
    (section_id, hero_badge, hero_title, hero_description,
     jobs_badge, jobs_title, jobs_description,
     empty_title, empty_description)
SELECT
    1,
    'CAREERS',
    'Software for people who work a counter, not a keyboard',
    'We build for cashiers, stock clerks, HR officers and store owners. If that sounds like work worth doing, come build it with us.',
    'OPEN JOB',
    'Open Positions',
    'Explore our current job openings and find the right opportunity for you.',
    'No Open Positions',
    'There are currently no available job openings. Please check again later.'
WHERE NOT EXISTS (SELECT 1 FROM website_careers_section);


INSERT INTO website_footer
    (footer_id, cta_badge, cta_title, cta_title_highlight, cta_description,
     cta_button_text, cta_button_link,
     brand_name, brand_description, contact_email, contact_phone, copyright_text)
SELECT
    1,
    'Ready to Get Started?',
    'Transform your retail business',
    'with RetailCore today.',
    'Join hundreds of retailers using RetailCore to simplify operations, improve inventory accuracy, manage employees, and increase profitability.',
    'Get Started',
    'pricing.php',
    'RetailCore',
    'Cloud-based enterprise retail management for convenience chains, mini marts, groceries, supermarkets and wholesalers.',
    'hello@retailcore.ph',
    '+63 2 8123 4567',
    'Copyright {year} RetailCore Retail OS. All rights reserved.'
WHERE NOT EXISTS (SELECT 1 FROM website_footer);


INSERT INTO website_footer_columns (column_id, column_order, heading)
SELECT * FROM (
    SELECT 1 AS a, 1 AS b, 'Platform'  AS c UNION ALL
    SELECT 2, 2, 'Solutions' UNION ALL
    SELECT 3, 3, 'Company'   UNION ALL
    SELECT 4, 4, 'Support'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM website_footer_columns);


INSERT INTO website_footer_links (column_id, link_order, label, url)
SELECT * FROM (
    SELECT 1 AS a, 1 AS b, 'Platform Overview'  AS c, 'platform.php'    AS d UNION ALL
    SELECT 1, 2, 'Features',            'features.php'    UNION ALL
    SELECT 1, 3, 'Marketplace',         'marketPlace.php' UNION ALL
    SELECT 1, 4, 'Pricing',             'pricing.php'     UNION ALL

    SELECT 2, 1, 'Convenience Stores',  'marketPlace.php' UNION ALL
    SELECT 2, 2, 'Mini Mart Chains',    'marketPlace.php' UNION ALL
    SELECT 2, 3, 'Supermarkets',        'marketPlace.php' UNION ALL
    SELECT 2, 4, 'Wholesale Retail',    'marketPlace.php' UNION ALL

    SELECT 3, 1, 'About Us',            'aboutUs.php'     UNION ALL
    SELECT 3, 2, 'Careers',             'careers.php'     UNION ALL
    SELECT 3, 3, 'Resources',           '#'               UNION ALL
    SELECT 3, 4, 'Contact Us',          'contactUs.php'   UNION ALL

    SELECT 4, 1, 'Support Center',      'contactUs.php'   UNION ALL
    SELECT 4, 2, 'Book a Demo',         'bookDemo.php'    UNION ALL
    SELECT 4, 3, 'Client Login',        '#'               UNION ALL
    SELECT 4, 4, 'System Status',       '#'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM website_footer_links);


INSERT INTO website_footer_legal_links (link_order, label, url)
SELECT * FROM (
    SELECT 1 AS a, 'Privacy Policy'   AS b, '#' AS c UNION ALL
    SELECT 2, 'Terms of Service', '#' UNION ALL
    SELECT 3, 'Data Processing',  '#'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM website_footer_legal_links);
