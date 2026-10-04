-- ============================================================
-- RetailSync - website content seed
--
-- Fills the tables created by website_content.sql with the
-- approved marketing copy. Re-running it resets the content
-- back to this baseline, so anything edited in the Super Admin
-- afterwards will be overwritten.
-- ============================================================

SET NAMES utf8mb4;


-- ------------------------------------------------------------
-- HERO
-- ------------------------------------------------------------

DELETE FROM website_hero;

INSERT INTO website_hero
    (hero_id, badge, title, description,
     primary_btn_text, primary_btn_link, secondary_btn_text, secondary_btn_link)
VALUES
    (1,
     'INTEGRATED RETAIL OPERATIONS PLATFORM',
     'Run your entire retail business from one platform.',
     'Manage sales, inventory, branches, employees, payroll, finance, and daily operations - all in one connected retail platform.',
     'Start Free Trial', 'pricing.php',
     'Book a Demo', 'bookDemo.php');


DELETE FROM website_hero_badges;

INSERT INTO website_hero_badges (badge_order, icon, label) VALUES
    (1, 'bi bi-calendar-check', '14-Day Free Trial'),
    (2, 'bi bi-grid-1x2',       'All-in-One Retail Platform'),
    (3, 'bi bi-diagram-3',      'Multi-Branch Ready'),
    (4, 'bi bi-clock-history',  '24/7 System Access');


-- ------------------------------------------------------------
-- WHY RETAILCORE
-- ------------------------------------------------------------

DELETE FROM website_why_section;

INSERT INTO website_why_section (section_id, badge, title, description)
VALUES
    (1,
     'WHY RETAILCORE',
     'Everything your retail business needs, connected in one platform.',
     'RetailCore connects sales, inventory, purchasing, workforce management, finance, and business analytics into one centralized retail operating system.');


DELETE FROM website_why_cards;

INSERT INTO website_why_cards (card_order, icon, title) VALUES
    (1, 'bi bi-boxes',        'All-in-One Platform'),
    (2, 'bi bi-graph-up',     'Real-Time Analytics'),
    (3, 'bi bi-diagram-3',    'Multi-Branch Ready'),
    (4, 'bi bi-shield-check', 'Secure Cloud System');


-- ------------------------------------------------------------
-- 8 CORE MODULES
-- ------------------------------------------------------------

UPDATE website_modules_section
SET badge       = '8 CORE MODULES',
    title       = 'One system, every part of the business',
    description = 'Activate what you need today and switch on the rest as you grow - no re-implementation.'
WHERE section_id = 1;

DELETE FROM website_modules;

INSERT INTO website_modules
    (module_id, module_name, module_description, icon, icon_bg, icon_color, module_order, status)
VALUES
    (1, 'Point of Sale',
        'Sales, payments, receipts, discounts and returns.',
        'bi bi-cart-check', 'bg-blue-100', 'text-blue-600', 1, 'Active'),

    (2, 'Inventory',
        'Stock-in, stock-out, adjustments, transfers and warehouse monitoring.',
        'bi bi-box-seam', 'bg-yellow-100', 'text-yellow-600', 2, 'Active'),

    (3, 'Purchasing',
        'Purchase requests, purchase orders, supplier management and receiving.',
        'bi bi-clipboard-check', 'bg-green-100', 'text-green-600', 3, 'Active'),

    (4, 'Human Resources',
        'Employee records, recruitment, time tracking and requests.',
        'bi bi-people', 'bg-purple-100', 'text-purple-600', 4, 'Active'),

    (5, 'Finance',
        'Payroll, financial records, approvals and financial reporting.',
        'bi bi-cash-coin', 'bg-emerald-100', 'text-emerald-600', 5, 'Active'),

    (6, 'Branch Management',
        'Monitor and manage multiple retail branches from one platform.',
        'bi bi-shop', 'bg-sky-100', 'text-sky-600', 6, 'Active'),

    (7, 'Reports & Analytics',
        'Business insights, sales reports, inventory reports and workforce reports.',
        'bi bi-bar-chart-line', 'bg-orange-100', 'text-orange-600', 7, 'Active'),

    (8, 'Promotions',
        'Manage promotions based on business and subscription access.',
        'bi bi-tags', 'bg-rose-100', 'text-rose-600', 8, 'Active');


-- ------------------------------------------------------------
-- UNIQUE SELLING POINT
-- ------------------------------------------------------------

DELETE FROM website_usp_section;

INSERT INTO website_usp_section (section_id, badge, title, description)
VALUES
    (1,
     'BUILT FOR MULTI-BRANCH RETAIL',
     'One Business. Multiple Branches. One Control Center.',
     'Manage every branch, employee, transaction, and inventory movement from a centralized platform.');


-- ------------------------------------------------------------
-- WHAT'S INCLUDED
-- ------------------------------------------------------------

DELETE FROM website_included_features;
DELETE FROM website_included_cards;

INSERT INTO website_included_section (section_id, badge, title, description)
VALUES
    (1,
     'WHAT''S INCLUDED',
     'One Platform. Every Part of Your Business.',
     NULL)
ON DUPLICATE KEY UPDATE
    badge = VALUES(badge),
    title = VALUES(title),
    description = VALUES(description);


INSERT INTO website_included_cards
    (card_id, card_order, icon, icon_bg, icon_color, title, description)
VALUES
    (1, 1, 'bi bi-cart-check',      'bg-blue-100',    'text-blue-600',
        'Point of Sale',            'Process transactions quickly across every branch.'),
    (2, 2, 'bi bi-box-seam',        'bg-yellow-100',  'text-yellow-600',
        'Inventory Management',     'Keep track of your products across every location.'),
    (3, 3, 'bi bi-person-badge',    'bg-purple-100',  'text-purple-600',
        'Staff Management',         'Manage employees and access across your branches.'),
    (4, 4, 'bi bi-people',          'bg-indigo-100',  'text-indigo-600',
        'HR Management',            'Manage your workforce from hiring to employee records.'),
    (5, 5, 'bi bi-cash-coin',       'bg-emerald-100', 'text-emerald-600',
        'Finance & Payroll',        'Simplify payroll and financial monitoring.'),
    (6, 6, 'bi bi-graph-up-arrow',  'bg-orange-100',  'text-orange-600',
        'Business Analytics',       'Turn your business data into useful insights.'),
    (7, 7, 'bi bi-shop',            'bg-sky-100',     'text-sky-600',
        'Multi-Branch Management',  'Control multiple stores from one centralized platform.'),
    (8, 8, 'bi bi-tags',            'bg-rose-100',    'text-rose-600',
        'Promotions',               'Create promotions based on your subscription and business needs.');


INSERT INTO website_included_features (card_id, feature_order, feature_name) VALUES
    -- Point of Sale
    (1, 1, 'Product Search'),
    (1, 2, 'Barcode Scanning'),
    (1, 3, 'Cash / E-Wallet Payments'),
    (1, 4, 'Discounts'),
    (1, 5, 'Receipt Printing'),
    (1, 6, 'Sales Tracking'),
    -- Inventory Management
    (2, 1, 'Stock In / Stock Out'),
    (2, 2, 'Low Stock Alerts'),
    (2, 3, 'Inventory Adjustments'),
    (2, 4, 'Branch Transfers'),
    (2, 5, 'Supplier Management'),
    (2, 6, 'Stock History'),
    -- Staff Management
    (3, 1, 'User Accounts'),
    (3, 2, 'Role-Based Access'),
    (3, 3, 'Staff Records'),
    (3, 4, 'Account Deactivation'),
    (3, 5, 'Branch Assignment'),
    (3, 6, 'Employee Status'),
    -- HR Management
    (4, 1, 'Job Posting'),
    (4, 2, 'Recruitment'),
    (4, 3, 'Employee Records'),
    (4, 4, 'Leave Requests'),
    (4, 5, 'Time & Requests'),
    (4, 6, 'Attendance'),
    -- Finance & Payroll
    (5, 1, 'Payroll Management'),
    (5, 2, 'Salary by Department'),
    (5, 3, 'Employee Salary Details'),
    (5, 4, 'Payroll Approval'),
    (5, 5, 'Leave History'),
    (5, 6, 'Financial Reports'),
    -- Business Analytics
    (6, 1, 'Sales Analytics'),
    (6, 2, 'Profit Analysis'),
    (6, 3, 'Inventory Reports'),
    (6, 4, 'Payroll Reports'),
    (6, 5, 'Branch Performance'),
    (6, 6, 'Business Trends'),
    -- Multi-Branch Management
    (7, 1, 'Branch Management'),
    (7, 2, 'Centralized Data'),
    (7, 3, 'Branch Performance'),
    (7, 4, 'Inventory Transfers'),
    (7, 5, 'Branch Staff'),
    (7, 6, 'Consolidated Reports'),
    -- Promotions
    (8, 1, 'Discounts'),
    (8, 2, 'Product Promotions'),
    (8, 3, 'Branch-Based Promotions'),
    (8, 4, 'POS Promotions'),
    (8, 5, 'Inventory Promotions'),
    (8, 6, 'HRMS / Finance features for Professional');


-- ------------------------------------------------------------
-- PLANS
-- ------------------------------------------------------------

-- Leftover test row from development. Hidden rather than deleted
-- so it can be recovered if it turns out to be wanted.
UPDATE subscription_plans SET status = 'Inactive' WHERE plan_name = 'sss';

UPDATE subscription_plans SET
    tagline       = 'For Small Convenience Stores',
    description   = 'For independent convenience stores ready to move beyond manual operations.',
    inherits_text = NULL,
    price_label   = NULL,
    badge         = 'None',
    button_text   = 'Start Free Trial',
    plan_order    = 1,
    status        = 'Active'
WHERE plan_id = 1;

UPDATE subscription_plans SET
    tagline       = 'For Growing Convenience Stores',
    description   = 'For growing retailers managing multiple branches, employees, and daily operations.',
    inherits_text = 'Includes everything in Starter PLUS:',
    price_label   = NULL,
    badge         = 'Most Popular',
    button_text   = 'Start Free Trial',
    plan_order    = 2,
    status        = 'Active'
WHERE plan_id = 2;

UPDATE subscription_plans SET
    tagline       = 'For Large Retail Networks & Franchises',
    description   = 'For businesses managing large-scale retail operations across multiple locations.',
    inherits_text = 'Includes everything in Professional PLUS:',
    price_label   = 'Custom Pricing',
    badge         = 'None',
    button_text   = 'Talk to Sales',
    plan_order    = 3,
    status        = 'Active'
WHERE plan_id = 3;


DELETE FROM subscription_plan_features WHERE plan_id IN (1, 2, 3);

INSERT INTO subscription_plan_features (plan_id, feature_order, feature_name) VALUES
    -- Retail Starter
    (1,  1, '1 Branch'),
    (1,  2, 'Up to 10 Users'),
    (1,  3, 'Point of Sale'),
    (1,  4, 'Inventory Management'),
    (1,  5, 'Product Management'),
    (1,  6, 'Staff Management'),
    (1,  7, 'Promotions'),
    (1,  8, 'Basic Reports'),
    (1,  9, 'Supplier Management'),
    (1, 10, 'Sales Tracking'),
    (1, 11, 'Low Stock Alerts'),
    (1, 12, 'Email Support'),
    -- Retail Professional
    (2,  1, 'Up to 10 Branches'),
    (2,  2, 'Unlimited Store Users'),
    (2,  3, 'Multi-Branch Management'),
    (2,  4, 'Centralized Inventory'),
    (2,  5, 'Branch-to-Branch Transfers'),
    (2,  6, 'HR Management'),
    (2,  7, 'Face Recognition Attendance'),
    (2,  8, 'Time & Request Management'),
    (2,  9, 'Payroll Management'),
    (2, 10, 'Leave Management'),
    (2, 11, 'Recruitment / Job Posting'),
    (2, 12, 'Finance Management'),
    (2, 13, 'Advanced Reports'),
    (2, 14, 'Business Analytics'),
    (2, 15, 'Advanced Promotions'),
    (2, 16, 'Supplier Management'),
    (2, 17, 'Priority Support'),
    -- Retail Enterprise
    (3,  1, 'Unlimited Branches'),
    (3,  2, 'Unlimited Users'),
    (3,  3, 'Unlimited POS Terminals'),
    (3,  4, 'Centralized Company Management'),
    (3,  5, 'Advanced Analytics'),
    (3,  6, 'Consolidated Financial Reports'),
    (3,  7, 'Advanced HR & Payroll'),
    (3,  8, 'Recruitment Management'),
    (3,  9, 'Custom Approval Workflows'),
    (3, 10, 'Advanced Inventory Controls'),
    (3, 11, 'API Access'),
    (3, 12, 'Custom Integrations'),
    (3, 13, 'Dedicated Account Manager'),
    (3, 14, 'Priority Support'),
    (3, 15, 'Custom Branding'),
    (3, 16, 'Multi-Company Support');


DELETE FROM subscription_plan_roles WHERE plan_id IN (1, 2, 3);

INSERT INTO subscription_plan_roles (plan_id, role_order, role_name) VALUES
    (1, 1, 'Owner / Admin'),
    (1, 2, 'Cashier'),
    (1, 3, 'Inventory Staff'),

    (2, 1, 'Owner / Admin'),
    (2, 2, 'HR Officer'),
    (2, 3, 'Inventory Staff'),
    (2, 4, 'Finance Staff'),
    (2, 5, 'Cashier');
