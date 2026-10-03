-- ============================================================
-- Company self-registration
--
-- Lets a new business create its own Owner/Admin account and
-- start a trial, instead of the Super Admin having to key every
-- tenant in by hand.
--
-- The existing verification flow (accounts/verify_email.php)
-- belongs to the `applications` table and is for job applicants,
-- so company owners need their own token columns here.
--
-- Additive and re-runnable.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS verification_token VARCHAR(64) NULL AFTER reset_token_expires_at,
    ADD COLUMN IF NOT EXISTS verification_expires_at DATETIME NULL AFTER verification_token,
    ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL AFTER verification_expires_at,
    ADD KEY IF NOT EXISTS idx_users_verification (verification_token);

-- Accounts that already exist predate this flow, so treat them as
-- verified rather than locking their owners out.
UPDATE users
SET email_verified_at = COALESCE(email_verified_at, join_date)
WHERE email_verified_at IS NULL;
