/*
|--------------------------------------------------------------------------
| BUSINESS SIZE COMES FROM THE PLAN, AND APPROVAL CARRIES A LINK
|--------------------------------------------------------------------------
|
| Two changes that go together, because both serve the path from registering
| to paying.
|
| 1. subscription_plans.business_size
|
|    register.php used to ask the owner for its branch count, employee count,
|    asset range and business size. That whole section is commented out of the
|    form, yet the four fields were still validated as required -- so every
|    registration failed on four errors the form no longer showed.
|
|    The plan a business picks already says how big it is, so the size is read
|    from here instead of asked for. Kept in the database rather than in PHP so
|    it can be changed without a deploy.
|
| 2. company.approval_token
|
|    The approval email linked to a bare subscribe.php, which had no idea who
|    was arriving and so opened with an email and password form -- for an owner
|    whose account cannot sign in yet. The token identifies the company from
|    the link itself, the way the email verification token already does.
|
|    It expires, and is cleared once the subscription goes Active, so a
|    forwarded email cannot be replayed later.
|
*/

ALTER TABLE subscription_plans
    ADD COLUMN business_size ENUM('Micro', 'Small', 'Medium', 'Large') NULL AFTER max_users;

UPDATE subscription_plans SET business_size = 'Small' WHERE plan_name = 'Retail Starter';
UPDATE subscription_plans SET business_size = 'Large' WHERE plan_name = 'Retail Professional';
UPDATE subscription_plans SET business_size = 'Large' WHERE plan_name = 'Retail Enterprise';

ALTER TABLE company
    ADD COLUMN approval_token VARCHAR(64) NULL AFTER reviewed_at,
    ADD COLUMN approval_token_expires_at DATETIME NULL AFTER approval_token;

CREATE INDEX idx_company_approval_token ON company (approval_token);
