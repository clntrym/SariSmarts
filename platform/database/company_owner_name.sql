-- ============================================================
-- RetailCore - separate owner name parts on company
--
-- Registration now asks for the owner's last, first and middle
-- name instead of first and last, the same way Add Platform User
-- and the HR employee form do.
--
-- owner_name stays and stays populated. The review screens, the
-- approval email and the company record all print it, so it
-- remains the display value composed from these three.
--
-- All three are nullable: companies registered before this only
-- ever had owner_name.
--
-- Safe to re-run.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE company
    ADD COLUMN IF NOT EXISTS owner_first_name  VARCHAR(40) NULL AFTER owner_name,
    ADD COLUMN IF NOT EXISTS owner_middle_name VARCHAR(40) NULL AFTER owner_first_name,
    ADD COLUMN IF NOT EXISTS owner_last_name   VARCHAR(40) NULL AFTER owner_middle_name;
