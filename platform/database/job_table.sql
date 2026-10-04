-- =========================================================
-- job — recruitment postings
-- =========================================================
--
-- Why this file exists at all:
--
--   `job` was created directly on the development database and never written
--   down as a migration. Every other table here has one. So when the
--   production database was built from a dump, `job` was simply absent -- and
--   because display_errors is Off in production, the six pages that read it
--   did not say so. hr/recruitment.php, hr/employee_directory.php,
--   hr/employee_registration.php and hr/archive_employee.php rendered their
--   header and then stopped; admin/approval.php and admin/reports.php
--   returned 500. All of them worked perfectly on XAMPP.
--
--   A table that exists in only one environment is a bug waiting for a
--   deployment. This is that table, written down.
--
-- Safe to run more than once: IF NOT EXISTS leaves an existing table alone.
--
-- Depends on: company, branch
-- =========================================================

CREATE TABLE IF NOT EXISTS `job` (
    `job_id`               INT(11)      NOT NULL AUTO_INCREMENT,
    `branch_id`            INT(11)      NOT NULL,
    `job_title`            VARCHAR(100) NOT NULL,
    `department`           VARCHAR(100) NOT NULL,
    `vacancies`            INT(11)      NOT NULL,
    `employment_type`      ENUM('Full-time','Part-time','Contract','Temporary','Internship') NOT NULL,
    `applications`         INT(11)      NOT NULL DEFAULT 0,
    `status`               VARCHAR(50)  NOT NULL,
    `interviews`           INT(11)      NOT NULL DEFAULT 0,
    `salary_min`           DECIMAL(10,2) DEFAULT NULL,
    `salary_max`           DECIMAL(10,2) DEFAULT NULL,
    `application_deadline` DATE          DEFAULT NULL,
    `job_description`      TEXT          DEFAULT NULL,
    `responsibilities`     TEXT          DEFAULT NULL,
    `qualifications`       TEXT          DEFAULT NULL,
    -- (CURRENT_DATE), not current_timestamp().
    --
    -- Development runs MariaDB 10.4, which accepts a DATE column defaulting to
    -- current_timestamp(); production runs MySQL 8.4, which rejects it outright
    -- -- "Invalid default value for 'created_at'" -- so the table could not be
    -- created there at all. The parenthesised expression form is accepted by
    -- both (MySQL 8.0.13+, MariaDB 10.2+), and recruitment.php does not supply
    -- this column on insert, so the default has to work.
    `created_at`           DATE         NOT NULL DEFAULT (CURRENT_DATE),

    -- Every operational table carries the company it belongs to. Without it a
    -- posting from one store would be visible to another.
    `company_id`           INT(11)      NOT NULL,

    PRIMARY KEY (`job_id`),
    KEY `branch_id` (`branch_id`),
    KEY `idx_job_company` (`company_id`),

    CONSTRAINT `fk_job_company` FOREIGN KEY (`company_id`)
        REFERENCES `company` (`company_id`) ON UPDATE CASCADE,
    CONSTRAINT `job_ibfk_1` FOREIGN KEY (`branch_id`)
        REFERENCES `branch` (`branch_id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
