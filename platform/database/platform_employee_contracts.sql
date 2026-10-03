-- ============================================================
-- SariSmart - employment contracts for the platform's own staff
--
-- SariSmarts gives a tenant's new hire a contract through
-- employee_contracts: a number, a title, the company's copy, the
-- signed copy back, a status and an HR review.
--
-- SariSmart's own people had nothing of the kind.
-- superAdmin/employees.php was a plain record of who works here.
--
-- This is the same shape, for platform_employees.
--
-- WHY A SEPARATE TABLE
--
-- employee_contracts.company_id is NOT NULL with a foreign key to
-- company, and SariSmart is not one of its own tenants. Putting
-- our staff in there would mean inventing a company row for
-- ourselves, and every tenant-scoped query in SariSmarts would
-- then have to learn to skip it. The two are kept apart for the
-- same reason platform_employees is kept apart from employees.
--
-- contract_number is globally unique, which is safe here because
-- it is built from platform_employees.employee_id - an
-- AUTO_INCREMENT, so it cannot collide. There is no company to
-- scope it to.
--
-- A contract exists only once somebody is hired, so the row is
-- created with the employee record and dies with it: the foreign
-- key cascades.
--
-- Safe to re-run.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS platform_employee_contracts (

    contract_id      INT(11)      NOT NULL AUTO_INCREMENT,
    employee_id      INT(11)      NOT NULL,

    contract_number  VARCHAR(30)  NOT NULL,
    contract_title   VARCHAR(150) NOT NULL,

    -- Paths under uploads/platform_contracts, which denies direct
    -- access. The files are served by superAdmin/contract_file.php,
    -- which checks the reader may open the Employees module first.
    company_contract VARCHAR(255) NULL,
    signed_contract  VARCHAR(255) NULL,

    -- Pending: drawn up, nothing sent yet.
    -- Sent:    our copy is attached and with the employee.
    -- Signed:  their signed copy is back.
    status           ENUM('Pending','Sent','Signed')      NOT NULL DEFAULT 'Pending',

    -- HR's verdict on the signed copy that came back.
    hr_review        ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    hr_remarks       TEXT         NULL,

    uploaded_by      INT(11)      NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (contract_id),
    UNIQUE KEY uniq_platform_contract_number (contract_number),
    KEY idx_platform_contract_employee (employee_id),

    CONSTRAINT fk_platform_contract_employee
        FOREIGN KEY (employee_id)
        REFERENCES platform_employees (employee_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
