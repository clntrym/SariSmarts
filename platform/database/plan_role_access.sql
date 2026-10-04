/*
|--------------------------------------------------------------------------
| PLAN ROLE ACCESS
|--------------------------------------------------------------------------
|
| subscription_plan_roles already listed the roles each plan advertises, but
| role_name is a marketing label ("Owner / Admin", "HR Officer") that only
| platform/pricing.php ever read. Nothing connected those labels to the role
| slugs RetailCore actually authorises with ('admin', 'hr', 'finance',
| 'inventory', 'cashier'), so a company on Retail Starter could still be given
| a Finance account.
|
| This adds an explicit slug column beside the label. pricing.php keeps
| rendering role_name untouched; RetailCore reads system_role.
|
| Enterprise is intentionally left with no rows of its own -- its copy reads
| "Includes everything in Professional PLUS:", and the pricing page relies on
| that empty list. Inheritance is resolved in code from inherits_text.
|
*/

ALTER TABLE subscription_plan_roles
    ADD COLUMN system_role VARCHAR(30) NULL AFTER role_name;

UPDATE subscription_plan_roles SET system_role = 'admin'     WHERE role_name = 'Owner / Admin';
UPDATE subscription_plan_roles SET system_role = 'cashier'   WHERE role_name = 'Cashier';
UPDATE subscription_plan_roles SET system_role = 'inventory' WHERE role_name = 'Inventory Staff';
UPDATE subscription_plan_roles SET system_role = 'hr'        WHERE role_name = 'HR Officer';
UPDATE subscription_plan_roles SET system_role = 'finance'   WHERE role_name = 'Finance Staff';

CREATE INDEX idx_plan_roles_system_role ON subscription_plan_roles (system_role);
