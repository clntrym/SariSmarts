/*
|--------------------------------------------------------------------------
| PLAN MODULE ACCESS
|--------------------------------------------------------------------------
|
| plan_role_access.sql tied each plan to the roles it may hand out. This does
| the same for the modules a plan may open.
|
| subscription_plan_features holds the bullet list the pricing page prints
| ("Multi-Branch Management"). Those strings are marketing copy, so the same
| trick applies: an explicit slug column beside the label. pricing.php keeps
| rendering feature_name and is untouched.
|
| A module is DENIED only when some plan explicitly grants it and this plan
| does not. Pages nothing claims -- Dashboard, Inventory, Suppliers, Reports,
| Settings -- stay open without needing a row of their own, so adding a page
| never silently hides it.
|
| Enterprise inherits from Professional the same way roles do (its
| inherits_text is set), but its own rows are mapped too so the grant survives
| someone editing the Professional list.
|
*/

ALTER TABLE subscription_plan_features
    ADD COLUMN system_module VARCHAR(40) NULL AFTER feature_name;

/* Retail Professional */
UPDATE subscription_plan_features SET system_module = 'branch'
    WHERE plan_id = 2 AND feature_name = 'Multi-Branch Management';
UPDATE subscription_plan_features SET system_module = 'hiring'
    WHERE plan_id = 2 AND feature_name = 'Recruitment / Job Posting';
UPDATE subscription_plan_features SET system_module = 'finance_approval'
    WHERE plan_id = 2 AND feature_name = 'Finance Management';

/* Retail Enterprise */
UPDATE subscription_plan_features SET system_module = 'branch'
    WHERE plan_id = 3 AND feature_name = 'Unlimited Branches';
UPDATE subscription_plan_features SET system_module = 'hiring'
    WHERE plan_id = 3 AND feature_name = 'Recruitment Management';
UPDATE subscription_plan_features SET system_module = 'finance_approval'
    WHERE plan_id = 3 AND feature_name = 'Consolidated Financial Reports';

CREATE INDEX idx_plan_features_system_module
    ON subscription_plan_features (system_module);

/*
| Retail Starter is sold as "For Small Convenience Stores" -- the owner works
| the counter and the stockroom, so there is no separate Inventory Staff seat.
| Its stock work is done by the admin instead.
*/
DELETE FROM subscription_plan_roles
    WHERE plan_id = 1 AND system_role = 'inventory';
