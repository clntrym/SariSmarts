/*
|--------------------------------------------------------------------------
| GOVERNMENT CONTRIBUTION RATES PER COMPANY
|--------------------------------------------------------------------------
|
| hr/hr_settings.php renders this table and lets HR edit the rates, and the
| table has never had a row -- so the screen has always shown an empty list
| with nothing to edit.
|
| The employee shares below are the published statutory rates. Withholding
| tax is deliberately left at 0.00: it is computed from BIR brackets, not a
| flat percentage, so putting a number there would be inventing one.
|
| Every company gets its own rows. A business can be exempt, or on a
| different scheme, and one tenant's edit must never move another's payroll.
|
*/

INSERT INTO government_contributions (company_id, contribution_name, deduction_rate)
SELECT c.company_id, r.name, r.rate
FROM company c
JOIN (
    SELECT 'SSS' AS name, 4.50 AS rate
    UNION ALL SELECT 'PhilHealth', 2.50
    UNION ALL SELECT 'Pag-IBIG', 2.00
    UNION ALL SELECT 'Withholding Tax', 0.00
) r
WHERE NOT EXISTS (
    SELECT 1 FROM government_contributions g
    WHERE g.company_id = c.company_id AND g.contribution_name = r.name
);

/*
| Same shape as the department and stock-request-code fixes: the name is
| tenant data, so it is unique inside a company rather than across all of them.
*/
ALTER TABLE government_contributions
    ADD UNIQUE KEY uq_contribution_per_company (company_id, contribution_name);
