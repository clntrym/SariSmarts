/*
|--------------------------------------------------------------------------
| DEPARTMENTS BELONG TO A COMPANY
|--------------------------------------------------------------------------
|
| hr/recruitment.php builds its Department dropdown from this table, and the
| table has never had a row in it -- so "Create Job Posting" has always
| offered an empty list.
|
| It also carried TWO globally unique indexes on department_name. Departments
| are per-company data, so the first business to create "Inventory" would have
| taken that name for the whole platform and every other tenant would have
| been refused it. Same shape as the stock_requests.request_code bug: the
| column is tenant data, the constraint was global.
|
| Both indexes are replaced by one composite key, and every company is seeded
| with the four departments the system actually staffs.
|
*/

ALTER TABLE department DROP INDEX department_name;
ALTER TABLE department DROP INDEX unique_department;

ALTER TABLE department
    ADD UNIQUE KEY uq_department_per_company (company_id, department_name);

INSERT INTO department (company_id, department_name)
SELECT c.company_id, d.name
FROM company c
JOIN (
    SELECT 'Inventory' AS name
    UNION ALL SELECT 'HR'
    UNION ALL SELECT 'Finance'
    UNION ALL SELECT 'Cashier'
) d
WHERE NOT EXISTS (
    SELECT 1 FROM department x
    WHERE x.company_id = c.company_id AND x.department_name = d.name
);
