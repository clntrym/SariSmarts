-- ============================================================
-- Tie every tenant row to a real company
--
-- NOT NULL alone was not enough: this MySQL runs without strict
-- mode, so an INSERT that omits company_id silently stores 0
-- rather than failing. A row owned by "company 0" is exactly the
-- unowned row the constraint was meant to prevent.
--
-- A foreign key closes that hole for good - 0 is rejected because
-- no company has that id, and it holds no matter what sql_mode is
-- set to. ON DELETE RESTRICT is deliberate: a company with live
-- data should be deactivated, never silently erased along with
-- its records.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `accounts_payable`
    ADD CONSTRAINT `fk_accounts_payable_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `applications`
    ADD CONSTRAINT `fk_applications_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `ap_payments`
    ADD CONSTRAINT `fk_ap_payments_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `ap_receipts`
    ADD CONSTRAINT `fk_ap_receipts_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `attendance`
    ADD CONSTRAINT `fk_attendance_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `attendance_corrections`
    ADD CONSTRAINT `fk_attendance_corrections_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `attendance_policy`
    ADD CONSTRAINT `fk_attendance_policy_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `branch`
    ADD CONSTRAINT `fk_branch_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `capital_ledger`
    ADD CONSTRAINT `fk_capital_ledger_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `categories`
    ADD CONSTRAINT `fk_categories_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `company_review_history`
    ADD CONSTRAINT `fk_company_review_history_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `company_subscriptions`
    ADD CONSTRAINT `fk_company_subscriptions_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `department`
    ADD CONSTRAINT `fk_department_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `employees`
    ADD CONSTRAINT `fk_employees_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `employee_biometrics`
    ADD CONSTRAINT `fk_employee_biometrics_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `employee_contracts`
    ADD CONSTRAINT `fk_employee_contracts_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `employee_documents`
    ADD CONSTRAINT `fk_employee_documents_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `employee_government_ids`
    ADD CONSTRAINT `fk_employee_government_ids_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `employment`
    ADD CONSTRAINT `fk_employment_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `expenses`
    ADD CONSTRAINT `fk_expenses_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `finance_capital`
    ADD CONSTRAINT `fk_finance_capital_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `government_contributions`
    ADD CONSTRAINT `fk_government_contributions_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `hiring_recommendations`
    ADD CONSTRAINT `fk_hiring_recommendations_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `interviews`
    ADD CONSTRAINT `fk_interviews_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `interview_results`
    ADD CONSTRAINT `fk_interview_results_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `inventory`
    ADD CONSTRAINT `fk_inventory_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `job`
    ADD CONSTRAINT `fk_job_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `leave_balances`
    ADD CONSTRAINT `fk_leave_balances_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `leave_requests`
    ADD CONSTRAINT `fk_leave_requests_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `overtime_requests`
    ADD CONSTRAINT `fk_overtime_requests_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `paymongo_sessions`
    ADD CONSTRAINT `fk_paymongo_sessions_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `payroll`
    ADD CONSTRAINT `fk_payroll_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `products`
    ADD CONSTRAINT `fk_products_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `sales`
    ADD CONSTRAINT `fk_sales_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `sale_items`
    ADD CONSTRAINT `fk_sale_items_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `stock_requests`
    ADD CONSTRAINT `fk_stock_requests_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `stock_request_items`
    ADD CONSTRAINT `fk_stock_request_items_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `suppliers`
    ADD CONSTRAINT `fk_suppliers_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `tax`
    ADD CONSTRAINT `fk_tax_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `undertime_requests`
    ADD CONSTRAINT `fk_undertime_requests_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `utang`
    ADD CONSTRAINT `fk_utang_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `utang_payments`
    ADD CONSTRAINT `fk_utang_payments_company`
    FOREIGN KEY (company_id) REFERENCES company (company_id)
    ON DELETE RESTRICT ON UPDATE CASCADE;
