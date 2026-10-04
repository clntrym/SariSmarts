-- ============================================================
-- RetailCore - the rest of an employee's record
--
-- The Add Employee screen was a modal with ten boxes. The new
-- one is a full page, and it asks for six things the table had
-- nowhere to put:
--
--   date_of_birth    personal
--   gender           personal
--   address          personal
--   salary           employment
--   supervisor_id    employment, points at another employee
--   profile_picture  a photograph
--
-- All six are nullable. Every one of them is optional on the
-- form, and the rows already in the table have none of them.
--
-- WHY supervisor_id IS SET NULL AND NOT CASCADE
--
-- A manager leaving does not delete their team. Their reports
-- lose a supervisor and keep their jobs, which is what actually
-- happens.
--
-- WHY salary IS DECIMAL AND NOT AN INT
--
-- Pay is money. An integer would quietly round every centavo
-- and nobody would see where it went.
--
-- Safe to re-run.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE platform_employees
    ADD COLUMN IF NOT EXISTS date_of_birth   DATE                                    NULL AFTER contact_number,
    ADD COLUMN IF NOT EXISTS gender          ENUM('Female','Male','Prefer not to say') NULL AFTER date_of_birth,
    ADD COLUMN IF NOT EXISTS address         VARCHAR(255)                            NULL AFTER gender,
    ADD COLUMN IF NOT EXISTS salary          DECIMAL(12,2)                           NULL AFTER employment_type,
    ADD COLUMN IF NOT EXISTS supervisor_id   INT(11)                                 NULL AFTER salary,
    ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255)                            NULL AFTER notes;


-- The supervisor is another employee on this same table.
-- Added separately: ADD CONSTRAINT has no IF NOT EXISTS, so
-- re-running the file would fail on the key rather than skip it.
SET @fk := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'platform_employees'
      AND CONSTRAINT_NAME = 'fk_platform_employee_supervisor'
);

SET @sql := IF(@fk = 0,
    'ALTER TABLE platform_employees
        ADD CONSTRAINT fk_platform_employee_supervisor
        FOREIGN KEY (supervisor_id) REFERENCES platform_employees (employee_id)
        ON DELETE SET NULL ON UPDATE CASCADE',
    'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
