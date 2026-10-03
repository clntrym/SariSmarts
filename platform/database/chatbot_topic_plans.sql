/*
| Which chatbot topics each plan opens.
|
| Deliberately NOT subscription_plan_features: that table is the bullet list
| the public pricing page prints, and the existing module slugs were attached
| to marketing rows that already existed ("Multi-Branch Management" ->
| 'branch'). The twelve chatbot topics have no such rows, so seeding them
| there would add twelve bullets to every pricing card -- a visible change to
| the sales page, made as a side effect of a gating decision.
|
| Every plan is seeded explicitly rather than relying on inherits_text, so the
| grant a company gets is the row that is actually there: one table to read
| when asking "why can this plan see payroll?".
*/
CREATE TABLE IF NOT EXISTS chatbot_topic_plans (
    topic   VARCHAR(40) NOT NULL,
    plan_id INT(11) NOT NULL,
    PRIMARY KEY (topic, plan_id),
    CONSTRAINT fk_chatbot_topic_plans_plan
        FOREIGN KEY (plan_id) REFERENCES subscription_plans(plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO chatbot_topic_plans (topic, plan_id) VALUES
    /* Retail Starter */
    ('pos', 1), ('inventory', 1), ('staff', 1), ('reports', 1),

    /* Retail Professional */
    ('pos', 2), ('inventory', 2), ('staff', 2), ('reports', 2),
    ('hrms', 2), ('recruitment', 2), ('attendance', 2), ('leave', 2),
    ('payroll', 2), ('finance', 2), ('branch', 2),

    /* Retail Enterprise */
    ('pos', 3), ('inventory', 3), ('staff', 3), ('reports', 3),
    ('hrms', 3), ('recruitment', 3), ('attendance', 3), ('leave', 3),
    ('payroll', 3), ('finance', 3), ('branch', 3), ('cross_branch', 3);
