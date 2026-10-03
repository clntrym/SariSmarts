-- ============================================================
-- Company tenancy - links platform subscriptions to app access
--
-- Before this, `company` and `company_subscriptions` existed but
-- nothing pointed back at them: users, branches and employees had
-- no company column. A subscription could expire and every user
-- still had full access, and two companies would have shared one
-- pool of data.
--
-- Additive and re-runnable. Existing rows are backfilled to the
-- first company so nothing is orphaned.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL AFTER user_id,
    ADD KEY IF NOT EXISTS idx_users_company (company_id);

ALTER TABLE branch
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL AFTER branch_id,
    ADD KEY IF NOT EXISTS idx_branch_company (company_id);

ALTER TABLE employees
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL AFTER employee_id,
    ADD KEY IF NOT EXISTS idx_employees_company (company_id);


-- Backfill: everything that exists today belongs to the first
-- company on record.
SET @first_company = (SELECT MIN(company_id) FROM company);

UPDATE users     SET company_id = @first_company WHERE company_id IS NULL;
UPDATE branch    SET company_id = @first_company WHERE company_id IS NULL;
UPDATE employees SET company_id = @first_company WHERE company_id IS NULL;


-- Super Admin runs the platform itself, so it belongs to no tenant.
UPDATE users SET company_id = NULL WHERE LOWER(role) = 'super admin';
