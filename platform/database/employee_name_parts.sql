-- ============================================================
-- RetailCore - separate name parts on platform employees
--
-- The HR employee form now asks for last, first and middle name
-- instead of one free-text field, the same way Add Platform User
-- does.
--
-- full_name stays and stays populated. The staff table, the audit
-- summaries and the login link in the Employees module all print
-- it, so it remains the display value and these three are the
-- source it is composed from.
--
-- All three are nullable: rows entered before this only ever had
-- full_name.
--
-- Safe to re-run.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE platform_employees
    ADD COLUMN IF NOT EXISTS first_name  VARCHAR(40) NULL AFTER full_name,
    ADD COLUMN IF NOT EXISTS middle_name VARCHAR(40) NULL AFTER first_name,
    ADD COLUMN IF NOT EXISTS last_name   VARCHAR(40) NULL AFTER middle_name;
