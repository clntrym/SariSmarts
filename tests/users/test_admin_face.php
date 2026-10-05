<?php
/*
| Registering a face where there is no HR officer to do it.
|
| Attendance is taken by face. The face is captured during Employee
| Registration, which is an HRMS page -- and Retail Starter sells no HR
| role, so on that plan nobody could register one. An owner could hire
| staff and then watch every time-in fail, with no screen anywhere that
| would let them fix it.
|
| So the owner gets their own page for it. Not a copy of the HR flow: that
| one captures a face as one step of onboarding somebody new. This one does
| the single job of attaching a face to an employee who already exists, for
| the person who is both the owner and the HR department.
|
| WHO MAY TOUCH WHOSE FACE
|
| The HR version reads the employee from the session -- it registers the
| logged-in user's own face, so it is scoped by construction. This one takes
| an employee_id from the browser, which is a different thing entirely: every
| query has to name the company, or one business could write a biometric
| onto another's staff by changing a number in a request.
*/
require_once __DIR__ . '/bootstrap.php';

$conn = $GLOBALS['conn'];
$root = dirname(__DIR__, 2);

/* ------------------------------------------------------- the pages exist */

t_ok(is_file($root . '/admin/face_registration.php'),
    'the owner has a page for registering a face');
t_ok(is_file($root . '/admin/save_employee_face.php'),
    'and an endpoint that saves one');

$page = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'],
    '', (string) file_get_contents($root . '/admin/face_registration.php'));

$save = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'],
    '', (string) file_get_contents($root . '/admin/save_employee_face.php'));

/* ------------------------------------------------------------ who may use it */

foreach (['admin/face_registration.php' => $page,
          'admin/save_employee_face.php' => $save] as $name => $code) {

    t_ok(str_contains($code, "requireRole(['admin'"),
        "{$name} is the owner's, not open to any role");

    t_ok(str_contains($code, 'requireCompany()'),
        "{$name} knows which company is asking");
}

/*
| Every statement that reads or writes an employee names the company. Read
| from the source rather than trusted to review: this endpoint takes an
| employee id from the browser.
*/
/*
| Each prepare()'s SQL in full, not a slice of it.
|
| The first version of this matched from the keyword to the next quote --
| which in "SET biometric_type = 'Face'" is five words in, long before the
| WHERE. It failed on a statement that was correct, which is the better
| direction for a test to be wrong in, but wrong all the same.
*/
if (preg_match_all('~prepare\(\s*"([^"]*)"~s', $save, $found)) {

    foreach ($found[1] as $statement) {

        /*
        | users is in this list too. It is the table that says who somebody
        | IS, and the endpoint now reads it by an id from the browser --
        | without a company, that lookup would hand back another business's
        | staff member and everything after it would be correct about the
        | wrong person.
        */
        $touchesPeople = str_contains($statement, 'employees')
            || str_contains($statement, 'employee_biometrics')
            || preg_match('~\b(FROM|UPDATE|INTO)\s+users\b~i', $statement);

        if (!$touchesPeople) {
            continue;
        }

        $kind = strtoupper(strtok(trim($statement), " \n\t"));

        t_ok(str_contains($statement, 'company_id'),
            "this {$kind} against a person names the company");
    }
}

t_ok(substr_count($save, 'company_id') >= 2,
    'the endpoint names the company more than once -- on the lookup and the write');

/* ------------------------------------------- it lists people who exist here */

/*
| It listed the employees table, and on Retail Starter that table is empty.
|
| Employee rows are created by HR during onboarding, and a plan with no HR
| role has no onboarding -- its staff are created in User Management as
| users and nothing else. The owner opened the page and was told "No
| employees yet" directly beneath two accounts they had just made.
|
| So the page lists users, which is what staff ARE on this plan, and the
| employee row is created when a face is first registered. That is the
| convention hr/my_attendance.php already set for exactly this: it makes
| the row on demand and links the account to it, so every other page agrees
| from then on.
*/
t_ok(str_contains($page, 'FROM users'),
    'the page lists the accounts the owner actually created');

t_ok(str_contains($save, 'INSERT INTO employees'),
    'and the endpoint creates the employee row when there is none');

t_ok(str_contains($save, 'UPDATE users') && str_contains($save, 'employee_id'),
    'then links the account to it, so the rest of the system agrees');

/* --------------------------------------------- it writes where attendance reads */

/*
| cashier/attendance.php and employee/attendance.php both read
| eb.face_descriptor from employee_biometrics. A face saved anywhere else
| would register successfully and never be recognised.
*/
t_ok(str_contains($save, 'employee_biometrics'),
    'the face goes to the table the attendance pages read');
t_ok(str_contains($save, 'face_descriptor'),
    'as a descriptor, which is what they compare against');

/* ----------------------------------------------------- and it is reachable */

$header = (string) preg_replace(['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~'],
    '', (string) file_get_contents($root . '/admin/admin_header.php'));

t_ok(str_contains($header, 'face_registration.php'),
    'the sidebar has a way in');

/*
| Shown where the plan has no HR, because where it does the HR officer
| registers the face during onboarding and two routes to one job is a
| thing to explain rather than a feature.
*/
t_ok(str_contains($header, '$canHrms') && str_contains($header, '!$canHrms'),
    'and shows it on the plans that have no HR officer to do it instead');

/* ------------------------------------------------- the descriptor is checked */

/*
| face-api produces 128 floats. Anything else is a broken capture or
| somebody posting by hand, and storing it would mean a time-in that can
| never match.
*/
t_ok(str_contains($save, '128'),
    'a descriptor that is not 128 numbers is refused');

/* ------------------------------------- every function it calls exists */

/*
| I wrote auditLog() into save_employee_face.php. It would have been a
| fatal error on the first successful capture: that function lives in
| platform/includes/audit.php and belongs to the other application. Nothing
| on this side calls it, so nothing on this side defines it.
|
| Every other assertion above read the source and agreed with itself. This
| one asks the runtime, which is the only thing that knows what is actually
| defined -- and the fault was in a branch that only runs after a face is
| successfully captured, so a reviewer could read the file twice and not
| see it.
*/
$undefined = [];

foreach (['admin/face_registration.php', 'admin/save_employee_face.php'] as $rel) {

    /*
    | Comments, scripts and STRINGS all go. The strings matter: the SQL
    | inside them is full of things that look like calls -- NOW(), VALUES(),
    | and a table name followed by a bracketed column list.
    */
    $code = (string) preg_replace(
        ['~/\*.*?\*/~s', '~(?<!:)//[^\n]*~', '~<script.*?</script>~s',
         '~"(?:[^"\\\\]|\\\\.)*"~s', "~'(?:[^'\\\\]|\\\\.)*'~s"],
        '', (string) file_get_contents($root . '/' . $rel));

    /* Functions the file declares for itself. */
    preg_match_all('~function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(~', $code, $own);
    $ownNames = array_map('strtolower', $own[1]);

    if (!preg_match_all('~(?<![\$>:\w])([a-zA-Z_][a-zA-Z0-9_]*)\s*\(~', $code, $found)) {
        continue;
    }

    foreach (array_unique($found[1]) as $name) {

        if (in_array(strtolower($name), $ownNames, true)) {
            continue;
        }


        /* Language constructs and keywords are not functions. */
        if (in_array(strtolower($name), ['if', 'for', 'foreach', 'while', 'switch',
                                         'catch', 'function', 'fn', 'array', 'list',
                                         'isset', 'unset', 'empty', 'echo', 'print',
                                         'return', 'exit', 'die', 'include', 'require',
                                         'include_once', 'require_once', 'elseif',
                                         'match', 'use', 'new', 'static'], true)) {
            continue;
        }

        if (!function_exists($name)) {
            $undefined[] = $rel . ': ' . $name . '()';
        }
    }
}

sort($undefined);

t_same([], $undefined,
    "every function these pages call is defined once init.php is loaded:\n    "
    . implode("\n    ", $undefined));

t_done();
