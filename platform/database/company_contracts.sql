-- ============================================================
-- SariSmart - the service agreement a business signs
--
-- A business that avails a subscription is agreeing to terms,
-- and nothing recorded that. It chose a plan and waited to be
-- switched on.
--
-- Now: choosing the plan issues a contract. The business
-- downloads it, signs it, and uploads the signed copy, which
-- lands with the Super Admin to accept or refuse.
--
--   Issued    sent to the business, nothing back yet
--   Signed    their signed copy is uploaded
--
-- and separately, what the Super Admin made of it:
--
--   Pending   not looked at
--   Approved  accepted
--   Rejected  something is wrong; remarks say what
--
-- The two are kept apart for the same reason they are on
-- platform_employee_contracts: a contract can be signed and
-- still be waiting, and a rejected one is still signed.
--
-- WHY issued_template IS COPIED, NOT POINTED AT
--
-- The blank template lives in platform_settings and the Super
-- Admin can replace it whenever the terms change. The path to
-- the file a particular business was actually given is stored
-- on its own row, so changing the template tomorrow does not
-- silently rewrite what somebody agreed to yesterday.
--
-- ONE CONTRACT PER SUBSCRIPTION, NOT PER COMPANY
--
-- A business that renews or changes plan is agreeing again.
-- subscription_id carries that, and the unique key stops the
-- same subscription being issued two contracts.
--
-- Safe to re-run.
-- ============================================================

SET NAMES utf8mb4;


-- ---- where the blank template lives ------------------------

ALTER TABLE platform_settings
    ADD COLUMN IF NOT EXISTS contract_template      VARCHAR(255) NULL AFTER trial_days,
    ADD COLUMN IF NOT EXISTS contract_template_name VARCHAR(190) NULL AFTER contract_template;


-- ---- the contracts themselves ------------------------------

CREATE TABLE IF NOT EXISTS company_contracts (

    contract_id      INT(11)      NOT NULL AUTO_INCREMENT,
    company_id       INT(11)      NOT NULL,
    subscription_id  INT(11)      NULL,

    contract_number  VARCHAR(30)  NOT NULL,

    -- The copy this business was given, snapshotted at issue.
    issued_template  VARCHAR(255) NULL,

    -- What they sent back.
    signed_contract  VARCHAR(255) NULL,
    signed_at        DATETIME     NULL,

    status           ENUM('Issued','Signed')               NOT NULL DEFAULT 'Issued',
    review           ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    review_remarks   TEXT         NULL,
    reviewed_by      INT(11)      NULL,
    reviewed_at      DATETIME     NULL,

    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (contract_id),
    UNIQUE KEY uniq_company_contract_number (contract_number),
    UNIQUE KEY uniq_company_contract_subscription (subscription_id),
    KEY idx_company_contract_company (company_id),

    CONSTRAINT fk_company_contract_company
        FOREIGN KEY (company_id)
        REFERENCES company (company_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
