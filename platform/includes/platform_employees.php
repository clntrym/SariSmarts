<?php

/*
|--------------------------------------------------------------------------
| SARISMART'S OWN STAFF, SHARED
|--------------------------------------------------------------------------
|
| The list screen and the add/edit form both need these, and when each kept
| its own copy they disagreed: the form offered departments of "Finance" and
| "HR" while the column allows Engineering, Marketing, Sales, Support,
| Finance, People and Operations.
|
| "HR" is not one of them. Choosing it stored an empty string -- MySQL
| accepts an unknown enum value as '' rather than refusing it -- so an
| employee was filed under no department at all and nothing said so.
|
| The lists live here now, and they are the column's values. Adding a
| department means changing the enum and this file together; either on its
| own produces the same silent loss.
|
*/

if (!function_exists('employeeDepartments')) {

    /* The enum on platform_employees.department, in full. */
    function employeeDepartments(): array
    {
        return ['Engineering', 'Marketing', 'Sales', 'Support', 'Finance', 'People', 'Operations'];
    }
}

if (!function_exists('employeeTypes')) {

    function employeeTypes(): array
    {
        return ['Full-time', 'Part-time', 'Contract', 'Intern'];
    }
}

if (!function_exists('employeeStatuses')) {

    function employeeStatuses(): array
    {
        return ['Active', 'On Leave', 'Resigned', 'Terminated'];
    }
}

if (!function_exists('employeeGenders')) {

    function employeeGenders(): array
    {
        return ['Female', 'Male', 'Prefer not to say'];
    }
}


if (!function_exists('nextEmployeeCode')) {

    /* EMP-1001, EMP-1002, ... continuing from the highest existing code. */
    function nextEmployeeCode(mysqli $conn): string
    {
        $row = $conn->query("
            SELECT MAX(CAST(SUBSTRING(employee_code, 5) AS UNSIGNED)) AS top
            FROM platform_employees WHERE employee_code LIKE 'EMP-%'
        ")->fetch_assoc();

        return 'EMP-' . (max(1000, (int) ($row['top'] ?? 1000)) + 1);
    }
}
