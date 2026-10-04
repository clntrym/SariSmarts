-- =========================================================
-- SariSmart -> RetailCore, for the text held in the database
-- =========================================================
--
-- The rename in the code covers everything written in a file. These rows are
-- the rest: the public website's own words, which are edited in the Super
-- Admin website manager and so live in the database, not in a template.
-- Without this the pages would read "RetailCore" in the chrome and
-- "SariSmart" in the copy.
--
-- Two deliberate exceptions:
--
--   audit_log is not touched. It is a record of what happened, and what
--   happened was that somebody generated a file called
--   SariSmart_Service_Agreement.pdf. Rewriting a log to match today's name
--   is how a log stops being evidence.
--
--   The support address is not touched here. hello@sarismart.ph is a real
--   mailbox on a real domain; changing the text without owning
--   retailcore.ph would point customers at nothing. Change it in Super Admin
--   -> Settings once the new domain receives mail.
--
-- Safe to run more than once: REPLACE on a value already changed finds
-- nothing to change.
--
-- Depends on: platform_settings, website_footer, website_pricing_faq,
--             website_why_section
-- =========================================================

UPDATE `platform_settings`
SET `platform_name`          = REPLACE(`platform_name`, 'SariSmart', 'RetailCore'),
    `contract_template_name` = REPLACE(`contract_template_name`, 'SariSmart', 'RetailCore');

UPDATE `website_footer`
SET `brand_name`          = REPLACE(`brand_name`, 'SariSmart', 'RetailCore'),
    `copyright_text`      = REPLACE(`copyright_text`, 'SariSmart', 'RetailCore'),
    `cta_title_highlight` = REPLACE(`cta_title_highlight`, 'SariSmart', 'RetailCore'),
    `cta_description`     = REPLACE(`cta_description`, 'SariSmart', 'RetailCore');

UPDATE `website_pricing_faq`
SET `answer` = REPLACE(`answer`, 'SariSmart', 'RetailCore');

UPDATE `website_why_section`
SET `badge`       = REPLACE(REPLACE(`badge`, 'SARISMART', 'RETAILCORE'), 'SariSmart', 'RetailCore'),
    `description` = REPLACE(`description`, 'SariSmart', 'RetailCore');
