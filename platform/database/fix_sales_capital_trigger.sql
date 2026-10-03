/*
|--------------------------------------------------------------------------
| POS SALES MUST CREDIT THEIR OWN COMPANY
|--------------------------------------------------------------------------
|
| trg_sales_add_capital was written for the single-tenant build and never
| revisited. It carried two faults:
|
|   1. UPDATE finance_capital ... ORDER BY capital_id ASC LIMIT 1
|      No company filter. Every sale in the system credited whichever capital
|      row happened to have the lowest id -- so one store's takings landed in
|      another store's capital.
|
|   2. INSERT INTO capital_ledger (...) omitted company_id entirely.
|      The column is NOT NULL with no default and MySQL is not in strict
|      mode, so the row went in as company_id = 0. Once tenant_foreign_keys
|      added fk_capital_ledger_company, that 0 stopped matching any company
|      and the insert began to fail -- taking the whole sale with it:
|
|          "Payment received, but the sale could not be recorded"
|
|      The foreign key did its job. It surfaced fault 1 by refusing the row
|      that fault 2 was quietly writing.
|
| The rewrite scopes both statements to NEW.company_id, reads the balance back
| rather than guessing it, and creates the capital row when a company does not
| have one yet -- the same lazy bootstrap ensureCompanyCapital() performs in
| PHP, so a first-ever sale can never fail for want of a row.
|
*/

DROP TRIGGER IF EXISTS trg_sales_add_capital;

DELIMITER $$

CREATE TRIGGER trg_sales_add_capital
AFTER INSERT ON sales
FOR EACH ROW
BEGIN

    DECLARE v_capital_id INT DEFAULT NULL;
    DECLARE v_balance DECIMAL(12,2) DEFAULT 0.00;

    SELECT capital_id INTO v_capital_id
    FROM finance_capital
    WHERE company_id = NEW.company_id
    ORDER BY capital_id ASC
    LIMIT 1;

    IF v_capital_id IS NULL THEN

        INSERT INTO finance_capital (company_id, current_capital)
        VALUES (NEW.company_id, 0.00);

        SET v_capital_id = LAST_INSERT_ID();

    END IF;

    UPDATE finance_capital
    SET current_capital = current_capital + NEW.total_amount
    WHERE capital_id = v_capital_id;

    SELECT current_capital INTO v_balance
    FROM finance_capital
    WHERE capital_id = v_capital_id;

    INSERT INTO capital_ledger
        (company_id, type, reference_id, reference_code, amount, balance_after, description)
    VALUES
        (NEW.company_id,
         'Sale Income',
         NEW.sale_id,
         CONCAT('SALE-', LPAD(NEW.sale_id, 6, '0')),
         NEW.total_amount,
         v_balance,
         CONCAT('POS Sale #', NEW.sale_id));

END$$

DELIMITER ;
