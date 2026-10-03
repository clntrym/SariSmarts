-- ============================================================
-- Enforce tenant ownership
--
-- company_id was left nullable while the application code was
-- being updated, so a missed INSERT would surface as an unowned
-- row in testing rather than a fatal error. All modules now
-- stamp it, so the column becomes mandatory and an unowned row
-- stops being possible at all.
--
-- `users` is deliberately excluded: the Super Admin runs the
-- platform itself and belongs to no company, so its company_id
-- is legitimately NULL.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `accounts_payable` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `applications` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `ap_payments` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `ap_receipts` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `attendance` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `attendance_corrections` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `attendance_policy` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `branch` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `capital_ledger` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `categories` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `company_review_history` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `company_subscriptions` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `department` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `employees` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `employee_biometrics` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `employee_contracts` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `employee_documents` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `employee_government_ids` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `employment` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `expenses` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `finance_capital` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `government_contributions` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `hiring_recommendations` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `interviews` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `interview_results` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `inventory` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `job` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `leave_balances` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `leave_requests` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `overtime_requests` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `paymongo_sessions` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `payroll` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `products` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `sales` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `sale_items` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `stock_requests` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `stock_request_items` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `suppliers` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `tax` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `undertime_requests` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `utang` MODIFY company_id INT(11) NOT NULL;

ALTER TABLE `utang_payments` MODIFY company_id INT(11) NOT NULL;
