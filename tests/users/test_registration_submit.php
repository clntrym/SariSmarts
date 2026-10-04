<?php
/*
| Registration has to be able to finish, and has to say why when it cannot.
|
| Reported: every field filled, both certificates attached and recognised,
| and the page comes back with
|
|     Registration failed. Please try again.
|     Please correct the highlighted fields below.
|
| with nothing highlighted anywhere on it.
|
| Two faults again.
|
| The red one. Accepted certificates are copied into
| platform/uploads/business_documents/ -- a directory platform/.gitignore
| excludes, so it is in nobody's clone and nobody's container. copy() fails,
| the RuntimeException rolls the transaction back, and every registration on
| the deployed site has failed at exactly this point. The directory was never
| created by anything; it simply existed on the machine the code was written
| on.
|
| The yellow one. plan_id is a key the validation can set and the page never
| renders. "Please correct the highlighted fields below" with nothing
| highlighted is a dead end for the person reading it -- there is no field to
| go and look at. Every key the validation can set must have somewhere to
| appear, and that is checked here for all of them rather than for the one
| that happened to be caught.
*/
require_once __DIR__ . '/bootstrap.php';

$root = __DIR__ . '/../../';
$register = (string) file_get_contents($root . 'platform/register.php');

t_ok($register !== '', 'register.php is readable');

/* ------------------------------------------ the directory documents land in */

t_ok(str_contains($register, 'business_documents'),
    'accepted certificates are stored under business_documents');

/*
| It has to be created on demand. Relying on it existing is relying on the
| developer's own filesystem, which is not shipped.
*/
preg_match('/\$documentDirectory\s*=.*?;(.*?)foreach \(\$acceptedUploads/s', $register, $m);
$between = $m[1] ?? '';

t_ok(str_contains($between, 'mkdir'),
    'the document directory is created before anything is copied into it');

t_ok(str_contains($between, 'RuntimeException') || str_contains($between, 'throw'),
    'and a failure to create it is raised rather than discovered by copy()');

/* ------------------------------------- every error has somewhere to be seen */

/*
| Both halves of the pair: the keys the validation can set, and the keys the
| page READS in order to show something.
|
| The read has to exclude the assignment itself. Otherwise every key is
| trivially "displayed" by the very line that sets it -- which is how the
| first version of this test passed while plan_id was invisible on the page.
|
| permitProblem() in includes/permits.php sets its keys the same way, so both
| files are scanned for setters.
*/
$permits = (string) @file_get_contents($root . 'platform/includes/permits.php');

preg_match_all("/\\\$errors\s*\[\s*'([a-z_]+)'\s*\]\s*=[^=]/", $register . $permits, $set);
preg_match_all("/errors\s*\[\s*'([a-z_]+)'\s*\]\s*(?!=[^=])/", $register, $read);

$canBeSet = array_values(array_unique($set[1] ?? []));
$everShown = array_values(array_unique($read[1] ?? []));

t_ok($canBeSet !== [], 'the validation sets errors by name');
t_ok(count($canBeSet) > 10, 'and there are plenty of them to get wrong');

foreach ($canBeSet as $key) {

    /* 'general' is the page-wide banner and has its own place at the top. */
    if ($key === 'general') {
        continue;
    }

    t_ok(in_array($key, $everShown, true),
        "an error on '{$key}' has somewhere on the page to appear");
}

/* The one that was missing, named, so a regression reads as itself. */
t_ok(in_array('plan_id', $everShown, true),
    'a rejected plan is shown to the person, not only to the code');

t_done();
