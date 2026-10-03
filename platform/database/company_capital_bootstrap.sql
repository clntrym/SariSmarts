/*
|--------------------------------------------------------------------------
| EVERY COMPANY GETS ITS OWN CAPITAL, AND A WAY TO ADD TO IT
|--------------------------------------------------------------------------
|
| finance_capital was only ever read and decremented. Six files select from
| it, two subtract from it, and NOTHING has ever inserted a row -- the old
| single-tenant build simply had one seeded row carrying the 50000.00 column
| default.
|
| That leaves every company registered through the platform with no capital
| record at all, so the moment it tries to receive a delivery it is told
| "Capital record not found. Please contact Finance." On Retail Starter that
| is a dead end: the plan has no Finance seat to contact.
|
| Three fixes:
|
|   1. The 50000.00 default is retired. Inventing money for a business that
|      never put any in is worse than starting it at zero.
|   2. Every existing company without a row gets one, at 0.00.
|   3. capital_ledger gains a 'Capital Added' type, so putting money in is
|      recorded the same way spending it is. Without this the ledger could
|      only ever explain where money went, never where it came from.
|
*/

ALTER TABLE finance_capital
    ALTER COLUMN current_capital SET DEFAULT 0.00;

ALTER TABLE capital_ledger
    MODIFY COLUMN type ENUM('Sale Income', 'Stock Purchase', 'Capital Added') NOT NULL;

INSERT INTO finance_capital (company_id, current_capital)
SELECT c.company_id, 0.00
FROM company c
WHERE NOT EXISTS (
    SELECT 1 FROM finance_capital f WHERE f.company_id = c.company_id
);
