-- ============================================================
-- RetailCore - make a plan's entitlements enforceable
--
-- subscription_plan_roles.role_name and
-- subscription_plan_features.feature_name are display text from
-- the pricing page. The tenant app enforces slugs, which live in
-- system_role and system_module beside them.
--
-- Every one of those columns was NULL - 8 roles, 50 features -
-- and both helpers in SariSmarts/init.php fail open on an empty
-- result. companyPlanRoles() returned all five roles for every
-- plan, so Retail Starter handed out HR Officer, Finance Staff
-- and everything else it never sold.
--
-- This fills them in from the name. The same mapping lives in
-- includes/plan_entitlements.php and is re-derived whenever a plan
-- is saved, so an edit in the Super Admin cannot wipe it again.
--
-- WHAT IS NOT MAPPED
--
-- Only slugs the tenant app actually checks are set:
--   hiring            admin/approval.php
--   finance_approval  stock request sign-off
--
-- 'branch' is left unmapped on purpose. admin/branch.php is the
-- only way to create a branch and registration creates none, so
-- gating that page would stop a Starter company from ever
-- recording its single store. The branch entitlement is a count,
-- enforced against subscription_plans.max_branches.
--
-- Plans whose inherits_text is set (Professional, Enterprise)
-- take the union of every plan at or below their plan_order, so
-- they need no rows of their own.
--
-- Safe to re-run.
-- ============================================================

SET NAMES utf8mb4;


-- ---- roles ------------------------------------------------

UPDATE subscription_plan_roles
SET system_role = CASE LOWER(TRIM(role_name))
        WHEN 'owner / admin'   THEN 'admin'
        WHEN 'owner/admin'     THEN 'admin'
        WHEN 'hr officer'      THEN 'hr'
        WHEN 'finance staff'   THEN 'finance'
        WHEN 'inventory staff' THEN 'inventory'
        WHEN 'cashier'         THEN 'cashier'
        ELSE system_role
    END
WHERE system_role IS NULL OR system_role = '';


-- ---- modules ----------------------------------------------

UPDATE subscription_plan_features
SET system_module = CASE LOWER(TRIM(feature_name))
        WHEN 'recruitment / job posting' THEN 'hiring'
        WHEN 'recruitment management'    THEN 'hiring'
        WHEN 'finance management'        THEN 'finance_approval'
        ELSE system_module
    END
WHERE system_module IS NULL OR system_module = '';
