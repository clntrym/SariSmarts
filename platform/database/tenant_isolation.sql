-- ============================================================
-- Multi-tenant isolation
--
-- Every operational table gets its own company_id. A direct
-- column on each table, rather than joining back through
-- branch or employee, keeps the rule the same everywhere:
--
--     ... WHERE company_id = <the signed-in company>
--
-- Run while the tables are empty, so no row has to have its
-- owner guessed. NOT NULL is deliberately NOT applied yet --
-- that comes after the application code is filling it in, so
-- a missed INSERT fails loudly in testing rather than
-- silently writing an unowned row in production.
--
-- Additive and re-runnable.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `accounts_payable`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_accounts_payable_company (company_id);

ALTER TABLE `ap_payments`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_ap_payments_company (company_id);

ALTER TABLE `ap_receipts`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_ap_receipts_company (company_id);

ALTER TABLE `applications`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_applications_company (company_id);

ALTER TABLE `attendance`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_attendance_company (company_id);

ALTER TABLE `attendance_corrections`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_attendance_corrections_company (company_id);

ALTER TABLE `attendance_policy`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_attendance_policy_company (company_id);

ALTER TABLE `capital_ledger`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_capital_ledger_company (company_id);

ALTER TABLE `categories`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_categories_company (company_id);

ALTER TABLE `department`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_department_company (company_id);

ALTER TABLE `employee_biometrics`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_employee_biometrics_company (company_id);

ALTER TABLE `employee_contracts`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_employee_contracts_company (company_id);

ALTER TABLE `employee_documents`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_employee_documents_company (company_id);

ALTER TABLE `employee_government_ids`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_employee_government_ids_company (company_id);

ALTER TABLE `employment`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_employment_company (company_id);

ALTER TABLE `expenses`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_expenses_company (company_id);

ALTER TABLE `finance_capital`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_finance_capital_company (company_id);

ALTER TABLE `government_contributions`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_government_contributions_company (company_id);

ALTER TABLE `hiring_recommendations`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_hiring_recommendations_company (company_id);

ALTER TABLE `interview_results`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_interview_results_company (company_id);

ALTER TABLE `interviews`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_interviews_company (company_id);

ALTER TABLE `inventory`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_inventory_company (company_id);

ALTER TABLE `job`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_job_company (company_id);

ALTER TABLE `leave_balances`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_leave_balances_company (company_id);

ALTER TABLE `leave_requests`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_leave_requests_company (company_id);

ALTER TABLE `overtime_requests`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_overtime_requests_company (company_id);

ALTER TABLE `paymongo_sessions`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_paymongo_sessions_company (company_id);

ALTER TABLE `payroll`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_payroll_company (company_id);

ALTER TABLE `products`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_products_company (company_id);

ALTER TABLE `sale_items`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_sale_items_company (company_id);

ALTER TABLE `sales`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_sales_company (company_id);

ALTER TABLE `stock_request_items`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_stock_request_items_company (company_id);

ALTER TABLE `stock_requests`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_stock_requests_company (company_id);

ALTER TABLE `suppliers`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_suppliers_company (company_id);

ALTER TABLE `tax`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_tax_company (company_id);

ALTER TABLE `undertime_requests`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_undertime_requests_company (company_id);

ALTER TABLE `utang`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_utang_company (company_id);

ALTER TABLE `utang_payments`
    ADD COLUMN IF NOT EXISTS company_id INT(11) NULL,
    ADD KEY IF NOT EXISTS idx_utang_payments_company (company_id);
