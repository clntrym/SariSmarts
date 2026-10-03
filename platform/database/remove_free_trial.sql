-- ============================================================
-- Remove the free trial
--
-- Registration no longer hands out access. A new company now
-- records the plan it wants as a Pending subscription, and the
-- Super Admin activates it once payment is settled.
--
-- 'Pending' is added to the status enum because the previous set
-- (Trial/Active/Expired/Cancelled) had no way to say "signed up,
-- not paid yet" - Trial was doing that job.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE company_subscriptions
    MODIFY status ENUM('Pending','Trial','Active','Expired','Cancelled')
    NULL DEFAULT 'Pending';

-- No plan offers a trial any more.
UPDATE subscription_plans SET trial_days = 0;

-- Buttons that promised a trial.
UPDATE subscription_plans
SET button_text = 'Get Started'
WHERE button_text LIKE '%Free Trial%';

-- Hero trust badge. "No Setup Fee" matches what the pricing page
-- already claimed for Starter and Professional.
UPDATE website_hero_badges
SET icon = 'bi bi-wallet2', label = 'No Setup Fee'
WHERE label LIKE '%Free Trial%';

-- Any subscription still sitting on Trial becomes Pending, since
-- the trial it was granted no longer exists as an offer.
UPDATE company_subscriptions SET status = 'Pending' WHERE status = 'Trial';
