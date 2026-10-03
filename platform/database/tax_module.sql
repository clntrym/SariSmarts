/*
|--------------------------------------------------------------------------
| TAX MODULE
|--------------------------------------------------------------------------
|
| The `tax` table has always held one row per company carrying the percentage
| the POS applies, and cashier/pointofsales.php has always read it. Nothing
| has ever written to it -- exactly the gap finance_capital had. A company
| with no row is charged 0%, permanently, with no screen anywhere to change
| that.
|
| The management screen is added in includes/tax.php. This migration supplies
| the two things it needs from the database:
|
|   1. A row for every company, so a rate can be edited rather than conjured.
|      0.00 is the honest starting point -- picking 12% on a shop's behalf
|      would silently start charging its customers VAT.
|
|   2. tax_history. `tax` is a single row that gets overwritten, so without
|      this a change from 12% to 0% would leave no trace at all. That is a
|      question a business can be asked to answer, so the answer is recorded:
|      who changed it, when, and from what to what.
|
*/

CREATE TABLE IF NOT EXISTS tax_history (
    history_id  INT(11)       NOT NULL AUTO_INCREMENT,
    company_id  INT(11)       NOT NULL,
    old_rate    DECIMAL(5,2)  NULL,
    new_rate    DECIMAL(5,2)  NOT NULL,
    changed_by  INT(11)       NULL,
    note        VARCHAR(255)  NULL,
    changed_at  DATETIME      NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (history_id),
    KEY idx_tax_history_company (company_id),
    CONSTRAINT fk_tax_history_company
        FOREIGN KEY (company_id) REFERENCES company (company_id)
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO tax (company_id, tax_rate)
SELECT c.company_id, 0.00
FROM company c
WHERE NOT EXISTS (
    SELECT 1 FROM tax t WHERE t.company_id = c.company_id
);
