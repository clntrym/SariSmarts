-- ============================================================
-- SariSmart - allow the "Recommended" plan badge
--
-- Both plan modals have always offered Recommended in the badge
-- dropdown, but the column only accepted None, Most Popular and
-- Best Value, so picking it stored an empty badge instead.
--
-- Safe to re-run.
-- ============================================================

ALTER TABLE subscription_plans
    MODIFY badge ENUM('None','Most Popular','Recommended','Best Value')
    DEFAULT 'None';
