-- ============================================================
-- SariSmart - DTI and BIR registration details
--
-- Registration now asks for two documents instead of four, and
-- for the details printed on them rather than a bare number.
--
-- The existing columns are reused where they already mean the
-- right thing, so no data moves:
--
--   dti_sec_registration -> the DTI Business Name No.
--   dti_sec_document     -> the DTI certificate
--   tin_number           -> the BIR TIN
--   tin_document         -> the BIR 2303 certificate
--
-- business_reg_number, business_permit and their documents are
-- left alone. Nothing collects them any more, but rows captured
-- before this still hold them and throwing that away would lose
-- a record the reviewer may still need.
--
-- dti_expiry_date is stored rather than computed on read. It is
-- always registration + 5 years, but storing it lets the database
-- sort and filter on it, which is what the expiry monitor and the
-- notification bell need.
--
-- BIR has no expiry: a Certificate of Registration stays valid
-- until the business itself changes. Only DTI is dated.
--
-- Safe to re-run.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE company
    ADD COLUMN IF NOT EXISTS dti_registration_date DATE NULL AFTER dti_sec_document,
    ADD COLUMN IF NOT EXISTS dti_expiry_date       DATE NULL AFTER dti_registration_date,
    ADD COLUMN IF NOT EXISTS bir_registration_date DATE NULL AFTER tin_document,
    ADD COLUMN IF NOT EXISTS bir_rdo_code          VARCHAR(10) NULL AFTER bir_registration_date,
    ADD COLUMN IF NOT EXISTS bir_ocn               VARCHAR(40) NULL AFTER bir_rdo_code;

-- Lets the monitor find what is lapsing without scanning the table.
ALTER TABLE company
    ADD INDEX IF NOT EXISTS dti_expiry_date (dti_expiry_date);
