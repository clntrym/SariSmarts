-- ============================================================
-- RetailCore - separate name parts on user accounts
--
-- Add Platform User now asks for last, first and middle name
-- rather than one free-text field, so the parts are stored as
-- they were entered.
--
-- fullname stays and stays populated. Every screen that prints a
-- person's name reads it, and the login page, the sidebar, the
-- audit trail and the tenant apps all rely on it, so dropping it
-- to chase tidiness would mean touching all of them. It is the
-- display value; these three are the source.
--
-- All three are nullable: accounts created before this, and every
-- account a tenant creates for its own staff, only ever had
-- fullname.
--
-- Safe to re-run.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS first_name  VARCHAR(40) NULL AFTER fullname,
    ADD COLUMN IF NOT EXISTS middle_name VARCHAR(40) NULL AFTER first_name,
    ADD COLUMN IF NOT EXISTS last_name   VARCHAR(40) NULL AFTER middle_name;
