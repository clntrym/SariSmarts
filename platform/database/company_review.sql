-- ============================================================
-- Company review workflow + required business information
--
-- Flow:
--   Pending   -> submitted, waiting for Super Admin review
--   Rejected  -> sent back with a reason; the owner fixes and resubmits
--   Approved  -> reviewed, may now pay for a subscription
--   Active    -> subscription paid, staff can sign in
--
-- 'Approved' and 'Rejected' are new. The old enum could only say
-- Pending/Active, which had no way to express "reviewed and turned
-- down, here is why".
--
-- Additive and re-runnable.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE company
    MODIFY status ENUM('Pending','Approved','Rejected','Active','Suspended','Inactive')
    NULL DEFAULT 'Pending';

ALTER TABLE company
    ADD COLUMN IF NOT EXISTS business_reg_number VARCHAR(60) NULL AFTER tin_number,
    ADD COLUMN IF NOT EXISTS dti_sec_registration VARCHAR(60) NULL AFTER business_reg_number,
    ADD COLUMN IF NOT EXISTS business_permit VARCHAR(60) NULL AFTER dti_sec_registration,
    ADD COLUMN IF NOT EXISTS supporting_document VARCHAR(255) NULL AFTER business_permit,
    ADD COLUMN IF NOT EXISTS number_of_branches INT(11) NULL AFTER supporting_document,
    ADD COLUMN IF NOT EXISTS estimated_employees INT(11) NULL AFTER number_of_branches,
    ADD COLUMN IF NOT EXISTS business_asset_range VARCHAR(60) NULL AFTER estimated_employees,
    ADD COLUMN IF NOT EXISTS business_size ENUM('Micro','Small','Medium','Large') NULL AFTER business_asset_range;

-- Review outcome. review_reason is filled on both outcomes so an
-- approval can carry a note and a rejection always explains itself.
ALTER TABLE company
    ADD COLUMN IF NOT EXISTS review_reason TEXT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS reviewed_by INT(11) NULL AFTER review_reason,
    ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL AFTER reviewed_by,
    ADD COLUMN IF NOT EXISTS submitted_at DATETIME NULL AFTER reviewed_at;

-- Audit trail of every review decision, so a company that was
-- rejected twice and then approved still has its full history.
CREATE TABLE IF NOT EXISTS company_review_history (
    review_id   INT(11) NOT NULL AUTO_INCREMENT,
    company_id  INT(11) NOT NULL,
    action      ENUM('Submitted','Approved','Rejected','Resubmitted') NOT NULL,
    reason      TEXT NULL,
    reviewed_by INT(11) NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (review_id),
    KEY idx_review_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Companies that existed before this workflow are treated as already
-- reviewed, so they do not suddenly appear in the approval queue.
UPDATE company
SET submitted_at = COALESCE(submitted_at, created_at)
WHERE submitted_at IS NULL;
