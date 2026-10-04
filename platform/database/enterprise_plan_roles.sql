-- =========================================================
-- Retail Enterprise — write down the seats it sells
-- =========================================================
--
-- Retail Enterprise had no rows in subscription_plan_roles at all.
--
-- It behaved correctly anyway, because companyPlanRoles() in init.php falls
-- back to every role when a plan grants none:
--
--     if (count($granted) === 0) {
--         return $allRoles;
--     }
--
-- That fallback exists so a plan nobody has configured yet does not lock a
-- paying customer out of their own system. It is the right default. But it
-- means Enterprise was right for the wrong reason -- not because anyone said
-- "Enterprise gets everything", but because nobody had said anything.
--
-- The cost of leaving it: the day someone adds a single role to Enterprise in
-- the plan editor, the count stops being zero, the fallback stops applying,
-- and Enterprise silently collapses to that one seat. The most expensive plan
-- would lose four of its five roles because somebody ticked one box.
--
-- role_order and role_name are supplied, not left out. Both are NOT NULL with
-- no default: MariaDB quietly fills 0 and an empty string, MySQL 8.4 refuses
-- the row outright. The first version of this file omitted them, which
-- inserted five nameless seats on the development database and failed on
-- production -- the labels below are the ones customers actually read.
--
-- Safe to run more than once: the INSERT skips a role already present.
--
-- Depends on: subscription_plans, subscription_plan_roles
-- =========================================================

INSERT INTO `subscription_plan_roles` (`plan_id`, `role_order`, `role_name`, `system_role`)
SELECT p.plan_id, r.role_order, r.role_name, r.system_role
FROM `subscription_plans` p
JOIN (
              SELECT 1 AS role_order, 'Owner / Admin'   AS role_name, 'admin'     AS system_role
    UNION ALL SELECT 2,              'HR Officer',                    'hr'
    UNION ALL SELECT 3,              'Inventory Staff',               'inventory'
    UNION ALL SELECT 4,              'Finance Staff',                 'finance'
    UNION ALL SELECT 5,              'Cashier',                       'cashier'
) r
WHERE p.plan_name = 'Retail Enterprise'
  AND NOT EXISTS (
      SELECT 1
      FROM `subscription_plan_roles` existing
      WHERE existing.plan_id = p.plan_id
        AND existing.system_role = r.system_role
  );
