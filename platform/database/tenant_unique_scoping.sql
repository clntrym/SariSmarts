-- ============================================================
-- SariSmart - scope per-tenant unique names to the tenant
--
-- suppliers.unique_supplier_name was UNIQUE on supplier_name
-- alone. Supplier names are chosen by the shop owner, and two
-- shops naturally buy from the same wholesaler, so the first
-- company to register "puregold" took that name away from every
-- other company on the platform. The second one got:
--
--   Duplicate entry 'puregold' for key 'unique_supplier_name'
--
-- The application was already right: inventory/suppliers.php
-- checks for a duplicate with "AND company_id = ?", so it always
-- meant the name to be unique per shop. Only the index disagreed.
--
-- expenses.uq_expense_code has the same shape and would have
-- failed the same way. expense_code is built in
-- finance/expenses.php as EXP-YYYY-0001, counting up from the
-- highest code WITHIN THE COMPANY - so the second company to
-- record an expense in a year generates EXP-2026-0001 again and
-- collides. It has no rows yet, which is the only reason nobody
-- has hit it.
--
-- WHAT IS DELIBERATELY LEFT ALONE
--
-- Other tenant tables carry a unique index with no company_id,
-- and they are correct:
--
--   employees.employee_code        EMP + year + employee_id
--   employee_contracts.contract_number  CON-date-employee_id
--   ap_receipts.receipt_code       RCPT-year-payment_id
--   support_tickets.ticket_code    counted across the platform
--
-- Each is built from a global AUTO_INCREMENT, so it is unique
-- platform-wide by construction and never collides.
--
--   accounts_payable.uniq_ap_item       (item_id)
--   employment.uq_employment_employee   (employee_id)
--   interview_results.unique_...        (interview_id)
--   employees.application_id
--
-- These say "one row per parent", and the parent id is already
-- globally unique. Adding company_id would weaken them.
--
--   company.company_code, company.email   genuinely global
--
-- users.username is NOT changed here, though it has the same
-- shape. Platform staff rows carry company_id = NULL, and a
-- unique index treats every NULL as distinct - so scoping it by
-- company would stop enforcing uniqueness among platform
-- accounts altogether and let two Super Admins share a username.
-- That is a worse bug than the one it would fix.
--
-- Safe to re-run.
-- ============================================================

SET NAMES utf8mb4;


-- ---- suppliers --------------------------------------------

ALTER TABLE suppliers
    DROP INDEX IF EXISTS unique_supplier_name;

ALTER TABLE suppliers
    ADD UNIQUE KEY IF NOT EXISTS unique_supplier_per_company (company_id, supplier_name);


-- ---- expenses ---------------------------------------------

ALTER TABLE expenses
    DROP INDEX IF EXISTS uq_expense_code;

ALTER TABLE expenses
    ADD UNIQUE KEY IF NOT EXISTS uq_expense_code_per_company (company_id, expense_code);
