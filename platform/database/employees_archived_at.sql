/*
| When somebody left.
|
| hr/archive_employee.php set employment_status = 'Archived' and recorded no
| date, so "two people left this month" could not be answered -- and an
| advisory that cannot show its evidence is not worth having. Counting archived
| employees with no date would make the hiring suggestion fire forever after
| the first resignation, which is how an assistant gets ignored.
|
| Nullable: almost every row is somebody who has not left.
*/
ALTER TABLE employees
    ADD COLUMN archived_at DATETIME NULL AFTER employment_status;
