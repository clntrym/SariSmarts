<?php

require_once __DIR__ . "/init.php";
require_once __DIR__ . "/accounts/send_company_verification.php";
require_once __DIR__ . "/includes/permits.php";
require_once __DIR__ . "/includes/field_rules.php";

/*
|--------------------------------------------------------------------------
| KEEPING UPLOADS ACROSS A REJECTED SUBMISSION
|--------------------------------------------------------------------------
|
| A browser will not refill a file input -- it is a security rule, not a
| bug -- so a form that bounced for a typo in the email used to send the
| owner back to re-pick all four documents.
|
| Every document that passes its checks is moved into a staging folder the
| moment it arrives, keyed to this browser session. On the next attempt a
| field left empty falls back to its staged file, and the form says so.
|
| The folder sits outside the webroot -- these are unreviewed identity
| documents and nothing should be able to fetch them by URL -- and where
| exactly is decided by register_paths.php, because the answer differs
| between XAMPP and the deployed container. Staged files older than a day are
| swept on each request.
*/
require_once __DIR__ . '/register_paths.php';

const REGISTRATION_STAGING_TTL = 86400;

function registrationStagingDir(): string
{
    if (empty($_SESSION['registration_upload_token'])) {
        $_SESSION['registration_upload_token'] = bin2hex(random_bytes(16));
    }

    $dir = registrationStagingRoot() . DIRECTORY_SEPARATOR . $_SESSION['registration_upload_token'];

    /*
    | The result of mkdir() used to be discarded, and the move that follows
    | was the first thing to notice the directory was not there -- by failing,
    | and telling the owner to try again at something that could never work.
    |
    | A staging directory that cannot be created is not a validation error
    | about the owner's file; it is the server being unable to do its job, and
    | it is raised so it reaches the log with a reason instead of arriving as
    | a sentence about their certificate.
    */
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create the upload staging directory: ' . $dir);
    }

    return $dir;
}

function registrationStaged(): array
{
    $staged = $_SESSION['registration_staged_uploads'] ?? [];

    // Drop entries whose file has gone (swept, or cleared by a finished registration).
    foreach ($staged as $field => $entry) {
        if (empty($entry['path']) || !is_file($entry['path'])) {
            unset($staged[$field]);
        }
    }

    $_SESSION['registration_staged_uploads'] = $staged;

    return $staged;
}

function registrationStage(string $field, string $tmpPath, string $extension, string $originalName, int $size): bool
{
    $staged = registrationStaged();
    $target = registrationStagingDir() . '/' . $field . '.' . $extension;

    // A replacement of a different type would otherwise leave the old file behind.
    if (!empty($staged[$field]['path']) && $staged[$field]['path'] !== $target && is_file($staged[$field]['path'])) {
        unlink($staged[$field]['path']);
    }

    if (!move_uploaded_file($tmpPath, $target)) {
        return false;
    }

    $staged[$field] = [
        'path' => $target,
        'extension' => $extension,
        'name' => $originalName,
        'size' => $size,
    ];

    $_SESSION['registration_staged_uploads'] = $staged;

    return true;
}

function registrationClearStaged(): void
{
    foreach (registrationStaged() as $entry) {
        if (is_file($entry['path'])) {
            unlink($entry['path']);
        }
    }

    if (!empty($_SESSION['registration_upload_token'])) {
        $dir = registrationStagingRoot() . '/' . $_SESSION['registration_upload_token'];
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }

    unset($_SESSION['registration_staged_uploads'], $_SESSION['registration_upload_token']);
}

function registrationSweepStaging(): void
{
    if (!is_dir(registrationStagingRoot())) {
        return;
    }

    foreach (glob(registrationStagingRoot() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {

        $empty = true;

        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - REGISTRATION_STAGING_TTL) {
                unlink($file);
            } else {
                $empty = false;
            }
        }

        if ($empty) {
            @rmdir($dir);
        }
    }
}

registrationSweepStaging();

/*
|--------------------------------------------------------------------------
| COMPANY REGISTRATION
|--------------------------------------------------------------------------
|
| Registration only submits an application. Nothing is granted here:
|
|   Pending   -> submitted, waiting for Super Admin review
|   Rejected  -> sent back with a reason, the owner fixes and resubmits
|   Approved  -> may now pay for a subscription
|   Active    -> subscription paid, staff can finally sign in
|
| No plan is chosen at this stage. That happens after approval, when the
| owner pays, so an unreviewed application never carries a price.
|
| The role is stored as "admin" because that is what init.php already
| routes to the RetailCore admin dashboard. A new "owner" role would mean
| teaching every redirect switch in both projects about it.
|
*/

/*
| Someone already signed in cannot register a second business, but bouncing
| them to the homepage with no explanation just looks broken. Say what
| happened and give them a way out instead.
*/
$alreadySignedIn = isLoggedIn();

$errors = [];
$old = [];
$success = false;

/*
| Provinces and their cities. The province is checked against this list on
| submit; the city is only suggested from it, because a missing entry must
| never be the reason a real business cannot register.
*/
$phLocations = require __DIR__ . '/data/ph_locations.php';
$provinces = array_keys($phLocations);
sort($provinces);

/*
| The plan is chosen on pricing.php, which links here as
| register.php?plan=<id>. It is recorded as a Pending subscription so the
| reviewer sees what the business asked for, and it grants nothing until it
| is activated after payment.
|
| Arriving without one means the visitor skipped the pricing page, so they
| are sent back to it rather than shown a form that cannot be completed.
*/
$plans = [];

$planResult = $conn->query("
    SELECT plan_id, plan_name, tagline, monthly_price, price_label, badge,
           max_branches, max_users, business_size
    FROM subscription_plans
    WHERE status = 'Active'
    ORDER BY plan_order, plan_id
");

while ($planRow = $planResult->fetch_assoc()) {
    $plans[$planRow['plan_id']] = $planRow;
}

$selectedPlanId = (int) ($_GET['plan'] ?? $_POST['plan_id'] ?? 0);

if (!isset($plans[$selectedPlanId])) {
    header('Location: pricing.php');
    exit;
}

$selectedPlan = $plans[$selectedPlanId];


const MAX_DOCUMENT_BYTES = 5 * 1024 * 1024;

$allowedDocuments = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];

/*
| Two documents, both required: the DTI Certificate of Business Name
| Registration and the BIR Certificate of Registration (Form 2303).
|
| Registration used to ask for four numbers and four files. The two that
| have gone - a separate business registration number and the mayor's permit
| - were collected without anything being done with them, and a reviewer
| cannot verify a number without the paper behind it. Their columns stay in
| the table for businesses registered before this; nothing collects them now.
*/
$permitDocuments = [
    'dti_sec_document' => [
        'label' => 'DTI Certificate of Business Name Registration',
        'column' => 'dti_sec_document',
        'required' => true,
    ],
    'tin_document' => [
        'label' => 'BIR Certificate of Registration (Form 2303)',
        'column' => 'tin_document',
        'required' => true,
    ],
];


/*
| A request larger than post_max_size (40 MB) arrives with $_POST and $_FILES
| both empty -- PHP throws the body away before this script runs. Validating
| that as a normal submission produced fifteen "is required" errors against a
| form the owner had filled in completely. It is recognised here instead and
| answered with the one thing that is actually wrong.
*/
$postTooLarge = $_SERVER['REQUEST_METHOD'] === 'POST'
    && empty($_POST)
    && empty($_FILES)
    && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;

if ($postTooLarge) {
    $errors['general'] = 'Your documents were too large to send together. Each file must be 5 MB or '
        . 'smaller -- please re-attach smaller copies (a photo of a document can be resized, or saved '
        . 'as a PDF) and submit again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$postTooLarge) {

    $companyName = trim($_POST['company_name'] ?? '');
    /*
    | Business type is no longer asked for at registration. It is stored as
    | null rather than left to the column default of 'Retail Store', because
    | defaulting would label a wholesaler a retail store on the strength of a
    | question nobody answered. Super Admin can set it on company.php, and
    | resubmit.php still offers the field.
    */
    $businessType = null;
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $province = trim($_POST['province'] ?? '');
    $postalCode = trim($_POST['postal_code'] ?? '');
    /*
    | DTI and BIR, as printed on the two certificates.
    |
    | The DTI expiry is never asked for. A Certificate of Business Name
    | Registration runs five years from its registration date, so the expiry
    | is worked out from that - which is the only way the two can never
    | contradict each other on a form somebody filled in quickly.
    |
    | BIR has no expiry at all: Form 2303 stays valid until the business
    | itself changes.
    */
    $dtiNumber = preg_replace('/\D/', '', (string) ($_POST['dti_registration_number'] ?? ''));
    $dtiDate = trim($_POST['dti_registration_date'] ?? '');
    $dtiExpiry = dtiExpiryFrom($dtiDate);

    $birTin = normaliseTin($_POST['bir_tin'] ?? '');
    $birDate = trim($_POST['bir_registration_date'] ?? '');
    $birRdo = preg_replace('/\D/', '', (string) ($_POST['bir_rdo_code'] ?? ''));
    $birOcn = trim($_POST['bir_ocn'] ?? '');

    /* The TIN column still carries it, now in its formatted form. */
    $tin = $birTin ?? '';
    $planId = $selectedPlanId;

    /* The clock the stored dates were written by. */
    $dbToday = $conn->query('SELECT CURDATE() AS d')->fetch_assoc()['d'];

    /*
    | Branch count, employee count, asset range and business size are no
    | longer asked for -- that section is gone from the form. The size is
    | taken from the plan instead, because the plan a business picks already
    | states its scale, and the other three are left null rather than
    | invented.
    |
    | They used to be validated as required while being invisible, so every
    | registration failed on four errors nobody could see or fix.
    */
    $branches = null;
    $employees = null;
    $assetRange = null;
    $businessSize = isset($plans[$planId]) ? ($plans[$planId]['business_size'] ?? null) : null;

    /*
    | Asked for in three parts, the way a Philippine form asks: last, first,
    | middle. owner_name stays as the display value every screen already
    | prints, composed the way a name is read out.
    */
    $ownerLastName = trim($_POST['owner_last_name'] ?? '');
    $ownerFirstName = trim($_POST['owner_first_name'] ?? '');
    $ownerMiddleName = trim($_POST['owner_middle_name'] ?? '');
    $ownerName = composeFullName($ownerLastName, $ownerFirstName, $ownerMiddleName);
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $agreed = isset($_POST['agree']);

    $old = compact(
        'companyName',
        'address',
        'city',
        'province',
        'postalCode',
        'ownerMiddleName',
        'dtiNumber',
        'dtiDate',
        'birTin',
        'birDate',
        'birRdo',
        'birOcn',
        'branches',
        'employees',
        'assetRange',
        'businessSize',
        'planId',
        'ownerFirstName',
        'ownerLastName',
        'ownerName',
        'email',
        'phone'
    );


    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    /*
    | What each field may contain, from includes/field_rules.php, so the
    | browser's pattern attribute and this check are the same rule.
    |
    | Password and email are deliberately not held to a character set:
    | restricting a password only weakens it, and an email is validated
    | against the address format itself further down.
    */
    $errors['company_name'] = fieldProblem(
        $companyName, 'Business name', RULE_BUSINESS, true, 150,
        "letters, numbers, spaces and . , & ' ( ) -"
    );

    if ($errors['company_name'] === null && !preg_match('/\p{L}/u', $companyName)) {
        /*
        | "123" or "-- 45" is a typo, not a business name. A name only has to
        | carry one letter, so "7-Eleven" and "J&J 24/7 Store" still pass.
        */
        $errors['company_name'] = 'Business name must include letters, not numbers only.';
    }

    $errors['address'] = fieldProblem(
        $address, 'Business address', RULE_ADDRESS, true, 255,
        "letters, numbers, spaces and . , & ' ( ) # / -"
    );

    $errors['city'] = fieldProblem(
        $city, 'City or municipality', RULE_PLACE, true, 120,
        "letters, spaces, hyphens and apostrophes"
    );

    $errors['postal_code'] = fieldProblem(
        $postalCode, 'Postal code', RULE_POSTAL, false, 4,
        'four digits'
    );

    $errors['phone'] = fieldProblem(
        $phone, 'Phone', RULE_PHONE, false, 30,
        'digits, spaces and + ( ) -'
    );

    if ($province === '') {
        $errors['province'] = 'Province is required.';
    }

    /*
    | One rule set, shared with resubmit.php and the Super Admin review, so
    | a certificate accepted on one screen is accepted on all of them.
    | $today comes from the database rather than PHP, because the dates it
    | is compared against were written by the database.
    */
    $errors += permitProblem($_POST, $dbToday);

    if (!in_array($province, $provinces, true)) {
        $errors['province'] = 'Please choose a province from the list.';
    }

    if (!isset($plans[$planId])) {
        $errors['plan_id'] = 'Please choose the plan you want.';
    }

    /*
    | The owner's name, held to the same rule as every other person name in
    | the system: letters, spaces, hyphens and apostrophes, so Dela Cruz,
    | Ma-ann and D'Souza all pass and a digit or a symbol does not.
    */
    $errors['owner_last_name'] = namePartProblem($ownerLastName, 'Last name', true);
    $errors['owner_first_name'] = namePartProblem($ownerFirstName, 'First name', true);
    $errors['owner_middle_name'] = namePartProblem($ownerMiddleName, 'Middle name', false);

    if (mb_strlen($ownerName) > 100) {
        $errors['owner_last_name'] = 'Those three names are too long to store together.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors['password'] = 'Password must contain both letters and numbers.';
    }

    if ($password !== $confirm) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }

    if (!$agreed) {
        $errors['agree'] = 'Please accept the terms to continue.';
    }


    /*
    |--------------------------------------------------------------------------
    | SUPPORTING DOCUMENT
    |--------------------------------------------------------------------------
    |
    | The MIME type is read from the file itself rather than trusted from
    | the browser, and the stored name is generated here, so a caller
    | cannot dictate the extension a file lands with.
    |
    */

    /*
    | The type is read from the file's own bytes with finfo rather than from
    | the name or the browser's claim, so renaming a script to .pdf does not
    | get it past this.
    */
    $acceptedUploads = [];
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    foreach ($permitDocuments as $field => $spec) {

        $file = $_FILES[$field] ?? null;
        $missing = !$file || $file['error'] === UPLOAD_ERR_NO_FILE;

        if ($missing) {

            // Nothing new chosen: the file kept from an earlier attempt stands in.
            $staged = registrationStaged();

            if (!empty($staged[$field])) {
                $acceptedUploads[$field] = [
                    'staged' => $staged[$field]['path'],
                    'extension' => $staged[$field]['extension'],
                    'column' => $spec['column'],
                ];
                continue;
            }

            if ($spec['required']) {
                $errors[$field] = 'Please attach the ' . $spec['label'] . '.';
            }

            continue;
        }

        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            $errors[$field] = 'File is too large. Maximum size is 5 MB.';
            continue;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[$field] = 'The file could not be uploaded. Please try again.';
            continue;
        }

        if ($file['size'] > MAX_DOCUMENT_BYTES) {
            $errors[$field] = 'File is too large. Maximum size is 5 MB.';
            continue;
        }

        $detectedType = $finfo->file($file['tmp_name']);

        if (!isset($allowedDocuments[$detectedType])) {
            $errors[$field] = 'Only PDF, JPG or PNG files are accepted.';
            continue;
        }

        /*
        | Staged immediately, before the rest of the form is judged, so a
        | failure anywhere else on the page does not cost the owner this file.
        */
        $extension = $allowedDocuments[$detectedType];

        if (!registrationStage($field, $file['tmp_name'], $extension, (string) $file['name'], (int) $file['size'])) {
            $errors[$field] = 'The file could not be saved. Please try again.';
            continue;
        }

        $acceptedUploads[$field] = [
            'staged' => registrationStaged()[$field]['path'],
            'extension' => $extension,
            'column' => $spec['column'],
        ];
    }


    // Uniqueness
    if (!isset($errors['email'])) {

        $check = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
        $check->bind_param("s", $email);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $errors['email'] = 'An account with this email already exists.';
        }

        $check->close();
    }

    if (!isset($errors['company_name'])) {

        $check = $conn->prepare("SELECT company_id FROM company WHERE company_name = ? LIMIT 1");
        $check->bind_param("s", $companyName);
        $check->execute();

        if ($check->get_result()->num_rows > 0) {
            $errors['company_name'] = 'A business with this name is already registered.';
        }

        $check->close();
    }


    /*
    | fieldProblem() and namePartProblem() answer null when a value is fine,
    | and those nulls were landing in $errors as keys. empty() counts a key
    | with a null value as present, so every submission would have been held
    | back by fields that had nothing wrong with them.
    */
    $errors = array_filter($errors, static function ($message) {
        return $message !== null && $message !== '';
    });


    /*
    |--------------------------------------------------------------------------
    | SUBMIT THE APPLICATION
    |--------------------------------------------------------------------------
    */

    if (empty($errors)) {

        $storedFiles = [];

        $conn->begin_transaction();
        $storedDocumentPath = null;

        try {

            $codeRow = $conn->query("
                SELECT MAX(CAST(SUBSTRING(company_code, 6) AS UNSIGNED)) AS max_code
                FROM company WHERE company_code LIKE 'COMP-%'
            ")->fetch_assoc();

            $companyCode = 'COMP-' . (max(1000, (int) ($codeRow['max_code'] ?? 1000)) + 1);

            /*
            | Created on demand, because it is in nobody's clone.
            |
            | platform/.gitignore excludes uploads/, so this folder exists on
            | the machine the code was written on and on no other. On Render
            | copy() therefore failed, the RuntimeException below rolled the
            | whole registration back, and the owner was told "Registration
            | failed. Please try again" -- at something that would fail every
            | time, for every business, forever.
            |
            | The failure is raised here rather than left for copy() to find,
            | so the log says the directory could not be made instead of
            | saying a certificate could not be stored.
            */
            $documentDirectory = __DIR__ . '/uploads/business_documents/';

            if (!is_dir($documentDirectory)
                && !@mkdir($documentDirectory, 0775, true)
                && !is_dir($documentDirectory)) {
                throw new RuntimeException(
                    'Could not create the document directory: ' . $documentDirectory
                );
            }

            /*
            | Everything is inside the surrounding transaction, so a database
            | failure rolls the rows back -- but a moved file is not rolled
            | back with it. The paths are collected here so the catch below can
            | delete whatever was already written.
            */
            $storedFiles = [];
            $documentPaths = [
                'dti_sec_document' => null,
                'tin_document' => null,
            ];

            foreach ($acceptedUploads as $field => $upload) {

                $documentName = $companyCode . '_' . $field . '_'
                    . bin2hex(random_bytes(8)) . '.' . $upload['extension'];

                $storedPath = $documentDirectory . $documentName;

                /*
                | Copied, not moved. If the transaction rolls back, the staged
                | copy is still there for the owner's next attempt; it is only
                | cleared once the registration has actually committed.
                */
                if (!copy($upload['staged'], $storedPath)) {
                    throw new RuntimeException('Could not store the ' . $field . '.');
                }

                $storedFiles[] = $storedPath;
                $documentPaths[$field] = 'uploads/business_documents/' . $documentName;
            }

            /*
            | supporting_document carries the Business Registration file too.
            | superAdmin/companyReview.php and resubmit.php both still read that
            | column, and a company registered today should not look
            | document-less to code that has not been told about the new ones.
            */
            /* supporting_document is the one older code reads, so the DTI
               certificate stands in for it. */
            $documentRelativePath = $documentPaths['dti_sec_document'];

            $dtiDocument = $documentPaths['dti_sec_document'];
            $tinDocument = $documentPaths['tin_document'];

            $stmt = $conn->prepare("
                INSERT INTO company
                    (company_code, company_name, business_type, owner_name,
                     owner_last_name, owner_first_name, owner_middle_name, email, phone,
                     address, city, province, postal_code,
                     supporting_document, number_of_branches, estimated_employees,
                     business_asset_range, business_size,
                     dti_sec_registration, dti_registration_date, dti_expiry_date, dti_sec_document,
                     tin_number, bir_registration_date, bir_rdo_code, bir_ocn, tin_document,
                     status, submitted_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, ?, 'Pending', NOW())
            ");

            /*
            | Nullable columns, and null is the honest value now that the form
            | does not ask. bind_param needs real variables to reference.
            */
            $branchCount = $branches;
            $employeeCount = $employees;

            /*
            | Empty strings would be stored as empty strings; these columns
            | mean "not given", which is null.
            */
            $rdoValue = $birRdo === '' ? null : $birRdo;
            $ocnValue = $birOcn === '' ? null : $birOcn;

            /* A blank middle name is "none", not an empty string. */
            $middleValue = $ownerMiddleName === '' ? null : $ownerMiddleName;

            $stmt->bind_param(
                "ssssssssssssssiisssssssssss",
                $companyCode,
                $companyName,
                $businessType,
                $ownerName,
                $ownerLastName,
                $ownerFirstName,
                $middleValue,
                $email,
                $phone,
                $address,
                $city,
                $province,
                $postalCode,
                $documentRelativePath,
                $branchCount,
                $employeeCount,
                $assetRange,
                $businessSize,
                $dtiNumber,
                $dtiDate,
                $dtiExpiry,
                $dtiDocument,
                $tin,
                $birDate,
                $rdoValue,
                $ocnValue,
                $tinDocument
            );
            $stmt->execute();
            $companyId = $stmt->insert_id;
            $stmt->close();


            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            /*
            | No verification token. Nothing emails one any more, so a token
            | stored here would be a live path to activating the account that
            | no legitimate flow ever uses -- and the Super Admin's approval is
            | what activates the account now.
            |
            | The account is created shut. acc_log_in.php turns away any user
            | whose status is 'inactive', which is exactly the intent while the
            | application is still being read.
            */
            $stmt = $conn->prepare("
                INSERT INTO users
                    (company_id, username, fullname, last_name, first_name, middle_name,
                     email, contact, password, role, status,
                     verification_token, verification_expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'admin', 'inactive', NULL, NULL)
            ");
            $stmt->bind_param(
                "issssssss",
                $companyId,
                $email,
                $ownerName,
                $ownerLastName,
                $ownerFirstName,
                $middleValue,
                $email,
                $phone,
                $hashedPassword
            );
            $stmt->execute();
            $stmt->close();


            /*
            | The requested plan, recorded as Pending. It carries no access
            | and no payment - the reviewer sees it while deciding, and it is
            | the row that gets activated once payment is settled.
            */
            $plan = $plans[$planId];
            $subStart = date('Y-m-d');
            $subExpiry = date('Y-m-d', strtotime('+1 month'));
            $subAmount = (float) $plan['monthly_price'];

            $stmt = $conn->prepare("
                INSERT INTO company_subscriptions
                    (company_id, plan_id, billing_cycle, amount, start_date, expiry_date,
                     payment_status, status, notes)
                VALUES (?, ?, 'Monthly', ?, ?, ?, 'Pending', 'Pending', ?)
            ");
            $subNote = 'Plan requested at registration. Awaiting review and payment.';
            $stmt->bind_param("iidsss", $companyId, $planId, $subAmount, $subStart, $subExpiry, $subNote);
            $stmt->execute();
            $stmt->close();


            $stmt = $conn->prepare("
                INSERT INTO company_review_history (company_id, action, reason)
                VALUES (?, 'Submitted', ?)
            ");
            $historyNote = 'Application submitted by ' . $ownerName . ' (' . $email . ').';
            $stmt->bind_param("is", $companyId, $historyNote);
            $stmt->execute();
            $stmt->close();

            $conn->commit();


            /*
            | No verification email. The owner's account stays inactive until
            | the Super Admin approves the application, and that approval is
            | what switches it on -- a real person reading the documents is a
            | stronger check than a click on a link, and it saves the owner a
            | step that proved nothing about the business.
            |
            | The token columns are still written above so an account created
            | before this change can finish verifying the old way.
            */
            $success = 'sent';

            // Committed: the documents live in business_documents now.
            registrationClearStaged();

            $old = [];

        } catch (Throwable $e) {

            $conn->rollback();

            // The rows are gone, so the orphaned uploads should go too.
            // A moved file is not part of the transaction and would otherwise
            // sit in the uploads directory belonging to nobody.
            foreach ($storedFiles as $orphan) {
                if (is_file($orphan)) {
                    unlink($orphan);
                }
            }

            $errors['general'] = 'Registration failed. Please try again.';
        }
    }
}


/*
| Read after the POST has been handled, so the form reflects anything staged
| on this very request as well as earlier ones.
*/
$stagedUploads = $success ? [] : registrationStaged();

include __DIR__ . "/header.php";

?>
<link rel="stylesheet" href="<?= $BASE_URL ?>/assets/css/permits.css">

<style>
    .staged-upload {
        display: flex;
        gap: 8px;
        align-items: flex-start;
        margin-top: 6px;
        padding: 8px 10px;
        border-radius: 8px;
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #065f46;
        font-size: 13px;
        line-height: 1.4;
    }

    .staged-upload i {
        margin-top: 2px;
    }

    .file-size-error {
        color: #dc3545;
        font-size: 13px;
        margin-top: 4px;
    }

</style>

<style>
    .reg-wrap {
        background: #f5f7fb;
        padding: 48px 0;
    }

    .reg-card {
        max-width: 960px;
        margin: 0 auto;
        background: #fff;
        border-radius: 20px;
        box-shadow: 0 12px 40px rgba(0, 0, 0, .08);
        overflow: hidden;
    }

    .reg-head {
        background: #00224c;
        color: #fff;
        padding: 34px 40px;
    }

    .reg-head h1 {
        font-size: 26px;
        font-weight: 700;
        margin: 0;
    }

    .reg-head p {
        color: #cbd5e1;
        margin: 8px 0 0;
    }

    .reg-body {
        padding: 34px 40px 40px;
    }

    .section-label {
        font-weight: 700;
        color: #00224c;
        margin: 28px 0 14px;
        font-size: 15px;
        padding-bottom: 10px;
        border-bottom: 1px solid #eef2f6;
    }

    .section-label:first-of-type {
        margin-top: 0;
    }

    /*
    |--------------------------------------------------------------------------
    | ONE INSET FOR EVERY SECTION
    |--------------------------------------------------------------------------
    |
    | The DTI and BIR fields sat 19px further right than every other field on
    | the page, because .permit-block wraps them in a bordered box (1px border
    | plus 18px padding) while Business Information and Owner / Admin Account
    | were bare rows against the body padding. Running an eye down the left
    | edge, the form stepped in and back out again.
    |
    | So every section is a block now, and each one puts its content exactly
    | 19px inside its own edge: 1px border + 18px padding, or for the plan
    | card, 2px border + 17px. Every box edge lines up with the body padding,
    | every field lines up with every other field, and the submit button
    | spans the same width as the boxes above it.
    |
    | Changing the border width of any of these means changing its padding by
    | the same amount, or that section steps out of line again.
    */
    .form-block,
    .agree-box {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 18px;
        background: #fff;
        margin-bottom: 18px;
    }

    .plan-summary {
        border: 2px solid #00224c;
        border-radius: 14px;
        padding: 17px;
        background: #f8fafc;
    }

    /*
    | The line under the plan card. It carries a link that used to borrow
    | .section-label, a block heading rule, and so drew a border across the
    | paragraph and pushed 28px of margin into the middle of a sentence.
    */
    .plan-note {
        color: #64748b;
        font-size: 13px;
        line-height: 1.6;
        margin: 10px 2px 0;
    }

    .plan-change {
        color: #00224c;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
        border-bottom: 1px solid #cbd5e1;
    }

    .plan-change:hover {
        color: #00224c;
        border-bottom-color: #00224c;
    }

    /* A note about a whole row of fields, quieter than a label. */
    .field-note {
        color: #64748b;
        font-size: 12.5px;
        line-height: 1.6;
        margin: -4px 2px 0;
    }

    /*
    | The confirmation sits in its own tinted band so it reads as a
    | deliberate step rather than one more line of small print above the
    | button.
    */
    .agree-box {
        margin-top: 26px;
        background: #f8fafc;
    }

    .agree-box .form-check-label {
        color: #334155;
        font-size: 14px;
        line-height: 1.5;
    }

    .btn-submit {
        background: #00224c;
        color: #fff;
        font-weight: 600;
        border-radius: 12px;
        margin-top: 20px;
        padding: 15px;
        transition: background .15s ease;
    }

    .btn-submit:hover {
        background: #001736;
        color: #fff;
    }

    .plan-price {
        font-size: 20px;
        font-weight: 700;
        color: #00224c;
    }

    .flow-step {
        display: flex;
        gap: 12px;
        align-items: flex-start;
    }

    .flow-num {
        width: 26px;
        height: 26px;
        border-radius: 50%;
        background: #e2e8f0;
        color: #475569;
        font-size: 13px;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        flex: none;
    }
</style>


<div class="reg-wrap">

    <div class="reg-card">

        <div class="reg-head">
            <h1>Register your business</h1>
            <p>Submit your details for review. Our team verifies every business before activation.</p>
        </div>

        <div class="reg-body">

            <?php if ($alreadySignedIn): ?>

                <div class="text-center py-4">

                    <div style="font-size:56px;color:#0d6efd;"><i class="bi bi-person-check"></i></div>

                    <h3 class="fw-bold mt-3" style="color:#00224c;">You are already signed in</h3>

                    <p class="text-muted mb-4">
                        You are signed in as
                        <strong><?= htmlspecialchars($_SESSION['fullname'] ?? 'a user') ?></strong>.
                        Registering a business starts a new account, so you need to sign out first.
                    </p>

                    <div class="d-flex gap-2 justify-content-center flex-wrap">
                        <a href="accounts/acc_log_out.php" class="btn text-white" style="background:#00224c;">
                            Sign out and register
                        </a>
                        <a href="index.php" class="btn btn-light border">Back to home</a>
                    </div>

                </div>

            <?php elseif ($success === 'sent'): ?>

                <div class="text-center py-4">
                    <div style="font-size:56px;color:#198754;"><i class="bi bi-send-check"></i></div>
                    <h3 class="fw-bold mt-3" style="color:#00224c;">Application submitted</h3>
                    <p class="text-muted mb-4">
                        Our team will review your business documents and email you the result.
                    </p>

                    <div class="text-start mx-auto" style="max-width:420px;">
                        <div class="flow-step mb-3">
                            <span class="flow-num">1</span>
                            <div><strong>We review your application</strong>
                                <div class="text-muted small">You will be emailed the result either way.</div>
                            </div>
                        </div>
                        <div class="flow-step mb-3">
                            <span class="flow-num">2</span>
                            <div><strong>Confirm your plan and pay</strong>
                                <div class="text-muted small">The approval email takes you straight there.</div>
                            </div>
                        </div>
                        <div class="flow-step">
                            <span class="flow-num">3</span>
                            <div><strong>Sign in</strong>
                                <div class="text-muted small">Access opens when your subscription is active.</div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($success === 'no_email'): ?>

                <div class="text-center py-4">
                    <div style="font-size:56px;color:#fbbd23;"><i class="bi bi-exclamation-triangle"></i></div>
                    <h3 class="fw-bold mt-3" style="color:#00224c;">Application submitted</h3>
                    <p class="text-muted">
                        Your application was received, but the verification email could not be sent.
                        Please contact support so we can verify your account.
                    </p>
                    <a href="contactUs.php" class="btn text-white mt-2" style="background:#00224c;">Contact Support</a>
                </div>

            <?php else: ?>

                <?php if (!empty($errors['general'])): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
                <?php endif; ?>

                <?php
                /*
                | Only when there is a field to go and look at.
                |
                | This read !empty($errors), which is true when the only
                | error is 'general' -- already shown in red immediately
                | above. The page then said "Please correct the highlighted
                | fields below" with nothing highlighted anywhere on it, and
                | the person was sent hunting through a form that was
                | entirely correct.
                */
                $fieldErrors = $errors;
                unset($fieldErrors['general']);
                ?>
                <?php if (!empty($fieldErrors)): ?>
                    <div class="alert alert-warning">
                        Please correct the highlighted fields below.
                    </div>
                <?php endif; ?>

                <?php
                /*
                | data-guard hands this form to assets/js/public-form-guard.js:
                | it applies the pattern and required attributes the browser
                | skips under novalidate, then asks once before sending.
                */
                ?>
                <form method="POST" enctype="multipart/form-data" id="registerForm" novalidate
                    data-guard
                    data-confirm-title="Submit this application?"
                    data-confirm-text="A reviewer will check your DTI and BIR documents and email the result to {email}. You cannot edit the application yourself once it is sent."
                    data-confirm-ok="Yes, submit">

                    <div class="section-label">Business Information</div>

                    <div class="form-block">

                    <div class="row g-3">

                        <div class="col-12">
                            <label class="form-label">Business Name <span class="text-danger">*</span></label>
                            <input type="text" name="company_name" maxlength="150" required
                                pattern="<?= htmlspecialchars(RULE_BUSINESS) ?>"
                                title="Letters, numbers, spaces and . , &amp; ' ( ) - only."
                                class="form-control <?= isset($errors['company_name']) ? 'is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($old['companyName'] ?? '') ?>">
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['company_name'] ?? '') ?></div>
                        </div>

                        <?php
                        /*
                        | Province first, then city: the city list is filtered
                        | by the province, so asking the other way round would
                        | offer every municipality in the country at once.
                        |
                        | Both are type-to-search inputs backed by a datalist.
                        | The province is checked against the list on submit;
                        | the city is not, so a municipality missing from the
                        | data never blocks a registration.
                        */
                        ?>
                        <div class="col-md-4">
                            <label class="form-label">Province <span class="text-danger">*</span></label>
                            <input type="text" name="province" id="provinceInput" list="provinceOptions" autocomplete="off"
                                placeholder="Type to search" maxlength="120" required
                                class="form-control <?= isset($errors['province']) ? 'is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($old['province'] ?? '') ?>">
                            <datalist id="provinceOptions">
                                <?php foreach ($provinces as $prov): ?>
                                    <option value="<?= htmlspecialchars($prov) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['province'] ?? '') ?></div>
                        </div>

                        <div class="col-md-5">
                            <label class="form-label">City / Municipality <span class="text-danger">*</span></label>
                            <input type="text" name="city" id="cityInput" list="cityOptions" autocomplete="off"
                                placeholder="Choose a province first" maxlength="120" required
                                pattern="<?= htmlspecialchars(RULE_PLACE) ?>"
                                title="Letters, spaces and . , ' ( ) - only."
                                class="form-control <?= isset($errors['city']) ? 'is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($old['city'] ?? '') ?>">
                            <datalist id="cityOptions"></datalist>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['city'] ?? '') ?></div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Postal Code</label>
                            <input type="text" name="postal_code" maxlength="4" inputmode="numeric"
                                pattern="<?= htmlspecialchars(RULE_POSTAL) ?>"
                                title="Four digits." placeholder="4232"
                                class="form-control <?= isset($errors['postal_code']) ? 'is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($old['postalCode'] ?? '') ?>">
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['postal_code'] ?? '') ?></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Street Address <span class="text-danger">*</span></label>
                            <?php
                            /*
                            | A textarea takes no pattern attribute, so the rule
                            | rides along in data-pattern and the guard script at
                            | the foot of the page applies it by hand.
                            |
                            | The city and province chosen above are appended to
                            | this field as you pick them, so the address reads
                            | whole without being typed twice. It stays an
                            | ordinary textarea: every character is editable,
                            | and what is typed here is never overwritten --
                            | only the trailing ", City, Province" is replaced
                            | when the choice above changes.
                            */
                            ?>
                            <textarea name="address" id="addressInput" rows="2" maxlength="255" required
                                data-pattern="<?= htmlspecialchars(RULE_ADDRESS) ?>"
                                title="Letters, numbers, spaces and . , &amp; ' ( ) # / - only."
                                class="form-control <?= isset($errors['address']) ? 'is-invalid' : '' ?>"
                                placeholder="House/building number, street, barangay"><?= htmlspecialchars($old['address'] ?? '') ?></textarea>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['address'] ?? '') ?></div>
                            <div class="form-text">
                                The city and province you chose are added here automatically.
                                Edit any part of it freely.
                            </div>
                        </div>

                    </div>

                    </div>


                    <div class="section-label">Registration &amp; Permits</div>

                    <p class="text-muted small mb-3">
                        Attach your DTI and BIR certificates. Choose the file first and we will
                        read the details off it, then check what we read against the paper.
                        PDF, JPG or PNG, maximum 5 MB each.
                    </p>


                    <!-- ===== DTI ===================================== -->

                    <div class="permit-block" data-permit-ocr="dti">

                        <div class="permit-block-head">
                            <span><i class="bi bi-file-earmark-text me-2"></i>DTI Certificate of Business Name Registration</span>
                            <span class="permit-block-note">Valid 5 years</span>
                        </div>

                        <div class="row g-3">

                            <div class="col-12">
                                <label class="form-label">Certificate file <span class="text-danger">*</span></label>
                                <input type="file" name="dti_sec_document" accept=".pdf,.jpg,.jpeg,.png"
                                    data-max-bytes="<?= MAX_DOCUMENT_BYTES ?>"
                                    class="form-control <?= isset($errors['dti_sec_document']) ? 'is-invalid' : '' ?>">
                                <?php if (!empty($stagedUploads['dti_sec_document'])): ?>
                                    <div class="staged-upload">
                                        <i class="bi bi-check-circle-fill"></i>
                                        <span>
                                            <strong><?= htmlspecialchars($stagedUploads['dti_sec_document']['name']) ?></strong>
                                            is already attached
                                            (<?= number_format($stagedUploads['dti_sec_document']['size'] / 1048576, 2) ?> MB).
                                            Choose a new file only to replace it.
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['dti_sec_document'] ?? '') ?></div>
                                <div class="permit-ocr-note" data-ocr-note hidden></div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Business Name No. <span class="text-danger">*</span></label>
                                <input type="text" name="dti_registration_number" inputmode="numeric" maxlength="10"
                                    placeholder="e.g. 4955922"
                                    class="form-control <?= isset($errors['dti_registration_number']) ? 'is-invalid' : '' ?>"
                                    value="<?= htmlspecialchars($old['dtiNumber'] ?? '') ?>">
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['dti_registration_number'] ?? '') ?></div>
                                <div class="form-text">Printed at the bottom of the certificate.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Registration date <span class="text-danger">*</span></label>
                                <input type="date" name="dti_registration_date" max="<?= htmlspecialchars($dbToday ?? date('Y-m-d')) ?>"
                                    class="form-control <?= isset($errors['dti_registration_date']) ? 'is-invalid' : '' ?>"
                                    value="<?= htmlspecialchars($old['dtiDate'] ?? '') ?>"
                                    data-dti-registration>
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['dti_registration_date'] ?? '') ?></div>
                                <div class="form-text">The "valid from" date on the certificate.</div>
                            </div>

                            <div class="col-12">
                                <!-- Not an input: the expiry is five years from the registration
                                     date by law, so asking for it would only create a second
                                     answer that could disagree with the first. -->
                                <div class="permit-derived" data-dti-expiry-box hidden>
                                    <i class="bi bi-calendar-check me-2"></i>
                                    <span>
                                        Valid until <strong data-dti-expiry></strong>
                                        <span class="permit-derived-sub">worked out as 5 years from your registration date</span>
                                    </span>
                                </div>
                            </div>

                        </div>

                    </div>


                    <!-- ===== BIR ===================================== -->

                    <div class="permit-block" data-permit-ocr="bir">

                        <div class="permit-block-head">
                            <span><i class="bi bi-receipt me-2"></i>BIR Certificate of Registration (Form 2303)</span>
                            <span class="permit-block-note">No expiry</span>
                        </div>

                        <div class="row g-3">

                            <div class="col-12">
                                <label class="form-label">Certificate file <span class="text-danger">*</span></label>
                                <input type="file" name="tin_document" accept=".pdf,.jpg,.jpeg,.png"
                                    data-max-bytes="<?= MAX_DOCUMENT_BYTES ?>"
                                    class="form-control <?= isset($errors['tin_document']) ? 'is-invalid' : '' ?>">
                                <?php if (!empty($stagedUploads['tin_document'])): ?>
                                    <div class="staged-upload">
                                        <i class="bi bi-check-circle-fill"></i>
                                        <span>
                                            <strong><?= htmlspecialchars($stagedUploads['tin_document']['name']) ?></strong>
                                            is already attached
                                            (<?= number_format($stagedUploads['tin_document']['size'] / 1048576, 2) ?> MB).
                                            Choose a new file only to replace it.
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['tin_document'] ?? '') ?></div>
                                <div class="permit-ocr-note" data-ocr-note hidden></div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">TIN and branch code <span class="text-danger">*</span></label>
                                <input type="text" name="bir_tin" maxlength="20"
                                    placeholder="e.g. 707-046-841-00000"
                                    class="form-control <?= isset($errors['bir_tin']) ? 'is-invalid' : '' ?>"
                                    value="<?= htmlspecialchars($old['birTin'] ?? '') ?>">
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['bir_tin'] ?? '') ?></div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Registration date <span class="text-danger">*</span></label>
                                <input type="date" name="bir_registration_date" max="<?= htmlspecialchars($dbToday ?? date('Y-m-d')) ?>"
                                    class="form-control <?= isset($errors['bir_registration_date']) ? 'is-invalid' : '' ?>"
                                    value="<?= htmlspecialchars($old['birDate'] ?? '') ?>">
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['bir_registration_date'] ?? '') ?></div>
                                <div class="form-text">Under Business Information Details.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">RDO code</label>
                                <input type="text" name="bir_rdo_code" inputmode="numeric" maxlength="3"
                                    placeholder="e.g. 036"
                                    class="form-control <?= isset($errors['bir_rdo_code']) ? 'is-invalid' : '' ?>"
                                    value="<?= htmlspecialchars($old['birRdo'] ?? '') ?>">
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['bir_rdo_code'] ?? '') ?></div>
                                <div class="form-text">From the Revenue District Office line.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">OCN</label>
                                <input type="text" name="bir_ocn" maxlength="40"
                                    placeholder="e.g. 036RC20240000002730"
                                    class="form-control <?= isset($errors['bir_ocn']) ? 'is-invalid' : '' ?>"
                                    value="<?= htmlspecialchars($old['birOcn'] ?? '') ?>">
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['bir_ocn'] ?? '') ?></div>
                                <div class="form-text">Top right of the certificate.</div>
                            </div>

                        </div>

                    </div>

                    <?php
                    /*
                    | The plan is chosen on pricing.php, which links here with
                    | ?plan=<id>. What was a second picker is now a summary of
                    | that choice -- asking twice invited the two to disagree.
                    */
                    ?>
                    <div class="section-label">Your Plan</div>

                    <div class="plan-summary d-flex flex-wrap justify-content-between align-items-center gap-3 mb-2">

                        <div>
                            <div class="fw-bold" style="color:#00224c;font-size:18px;">
                                <?= htmlspecialchars($selectedPlan['plan_name']) ?>
                            </div>
                            <div class="text-muted small">
                                <?= htmlspecialchars($selectedPlan['tagline'] ?? '') ?>
                            </div>
                        </div>

                        <div class="text-end">
                            <div class="plan-price">
                                &#8369;<?= number_format((float) $selectedPlan['monthly_price'], 2) ?>
                            </div>
                            <div class="text-muted small">
                                <?= htmlspecialchars($selectedPlan['price_label'] ?? 'per month') ?>
                            </div>
                        </div>

                    </div>

                    <input type="hidden" name="plan_id" value="<?= (int) $selectedPlanId ?>">

                    <?php
                    /*
                    | This link used to carry .section-label, which is a block
                    | heading rule - bold, 28px of top margin and a border along
                    | the bottom. Inline in a sentence it drew a bar under the
                    | last line of the paragraph.
                    */
                    ?>
                    <p class="plan-note">
                        You are not charged now &mdash; your subscription is activated only after
                        your application is approved and payment is settled.
                        <a href="pricing.php" class="plan-change">Choose a different plan</a>
                    </p>

                    <div class="section-label">Owner / Admin Account</div>

                    <div class="form-block">

                    <div class="row g-3">

                        <?php
                        /*
                        | Asked in the order a Philippine form asks: last, first,
                        | middle. The same three parts, the same rule and the
                        | same pattern as the platform user and employee forms.
                        */
                        ?>
                        <div class="col-md-4">
                            <label class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="owner_last_name" required
                                maxlength="<?= NAME_PART_MAX ?>"
                                pattern="<?= htmlspecialchars(NAME_PATTERN) ?>"
                                title="Letters, spaces, hyphens and apostrophes only."
                                class="form-control <?= isset($errors['owner_last_name']) ? 'is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($old['ownerLastName'] ?? '') ?>">
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['owner_last_name'] ?? '') ?></div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="owner_first_name" required
                                maxlength="<?= NAME_PART_MAX ?>"
                                pattern="<?= htmlspecialchars(NAME_PATTERN) ?>"
                                title="Letters, spaces, hyphens and apostrophes only."
                                class="form-control <?= isset($errors['owner_first_name']) ? 'is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($old['ownerFirstName'] ?? '') ?>">
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['owner_first_name'] ?? '') ?></div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="owner_middle_name"
                                maxlength="<?= NAME_PART_MAX ?>"
                                pattern="<?= htmlspecialchars(NAME_PATTERN) ?>"
                                title="Letters, spaces, hyphens and apostrophes only."
                                class="form-control <?= isset($errors['owner_middle_name']) ? 'is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($old['ownerMiddleName'] ?? '') ?>">
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['owner_middle_name'] ?? '') ?></div>
                            <div class="form-text">Leave blank if you have none.</div>
                        </div>

                        <div class="col-12">
                            <p class="field-note">
                                Names take letters, spaces, hyphens and apostrophes. Your email and
                                password are not restricted this way.
                            </p>
                        </div>

                        <?php
                        /*
                        | Six and six, twice. These were 6 + 4 and then 4 + 4,
                        | so both rows stopped short of the edge and the fields
                        | lined up with nothing above or below them.
                        |
                        | No character rule on email or password: an email is
                        | checked against the address format itself, and a
                        | password restricted to "safe" characters is only a
                        | weaker password.
                        */
                        ?>
                        <div class="col-md-6">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" required maxlength="150"
                                placeholder="you@example.com"
                                class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($old['email'] ?? '') ?>">
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['email'] ?? '') ?></div>
                            <div class="form-text">This is also your sign-in username.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" maxlength="30" inputmode="tel"
                                pattern="<?= htmlspecialchars(RULE_PHONE) ?>"
                                title="Digits, spaces and + ( ) - only." placeholder="0917 123 4567"
                                class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($old['phone'] ?? '') ?>">
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['phone'] ?? '') ?></div>
                            <div class="form-text">Optional.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" required
                                class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>">
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['password'] ?? '') ?></div>
                            <div class="form-text">8+ characters, with letters and numbers.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" name="confirm_password" required
                                class="form-control <?= isset($errors['confirm_password']) ? 'is-invalid' : '' ?>">
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['confirm_password'] ?? '') ?></div>
                            <div class="form-text">Type it once more.</div>
                        </div>

                    </div>

                    </div>


                    <div class="agree-box">
                        <div class="form-check mb-0">
                            <input class="form-check-input <?= isset($errors['agree']) ? 'is-invalid' : '' ?>"
                                type="checkbox" name="agree" id="agree">
                            <label class="form-check-label" for="agree">
                                I confirm the information above is accurate and I accept the
                                terms of service.
                            </label>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['agree'] ?? '') ?></div>
                        </div>
                    </div>


                    <button type="submit" class="btn btn-submit w-100">
                        Submit Application
                    </button>

                    <div class="text-center mt-3 text-muted">
                        Already have an account?
                        <a href="/accounts/acc_log_in.php" style="color:#00224c;font-weight:600;">Sign in</a>
                    </div>

                </form>

            <?php endif; ?>

        </div>

    </div>

</div>

<script>
    /*
    | The plan picker's highlight script went with the picker itself. What is
    | left is the one piece of behaviour this form needs: the city list
    | follows the province, so a business is offered the municipalities of its
    | own province rather than all 1,600 in the country.
    |
    | The city field still accepts a typed value that is not on the list --
    | a municipality missing from the data must not block a registration.
    */
    document.addEventListener("DOMContentLoaded", function () {

        var cities = <?= json_encode($phLocations, JSON_UNESCAPED_UNICODE) ?>;

        var provinceInput = document.getElementById("provinceInput");
        var cityInput = document.getElementById("cityInput");
        var cityOptions = document.getElementById("cityOptions");

        if (!provinceInput || !cityInput || !cityOptions) return;

        function fillCities(province, keepValue) {

            var list = cities[province] || [];

            cityOptions.innerHTML = "";

            list.forEach(function (name) {
                var option = document.createElement("option");
                option.value = name;
                cityOptions.appendChild(option);
            });

            cityInput.placeholder = list.length
                ? "Type to search"
                : "Choose a province first";

            if (!keepValue) {
                cityInput.value = "";
            }
        }

        // A submit that came back with errors keeps what was already typed.
        fillCities(provinceInput.value, true);


        /*
        | THE ADDRESS CARRIES THE CITY AND PROVINCE
        |
        | Picking them above writes them onto the end of Street Address, so
        | the address reads whole without being typed twice.
        |
        | The field stays an ordinary textarea. What somebody types is never
        | touched: only the tail this script wrote last time is replaced,
        | which is why the last tail is remembered rather than guessed at. A
        | tail that is no longer there -- because it was edited or deleted --
        | is simply not found, and nothing is removed.
        */
        var addressInput = document.getElementById("addressInput");

        var lastTail = "";

        function suffix() {

            var parts = [];

            if (cityInput.value.trim() !== "") parts.push(cityInput.value.trim());
            if (provinceInput.value.trim() !== "") parts.push(provinceInput.value.trim());

            return parts.length ? ", " + parts.join(", ") : "";
        }

        function syncAddress() {

            if (!addressInput) return;

            var value = addressInput.value;

            /* Take off what this script put on last time, if it survived. */
            if (lastTail !== "" && value.slice(-lastTail.length) === lastTail) {
                value = value.slice(0, -lastTail.length);
            }

            var tail = suffix();

            /*
            | Nothing to append to yet. Writing ", Tanauan, Batangas" into an
            | empty box would read as an address beginning with a comma.
            */
            if (value.trim() === "") {
                addressInput.value = value;
                lastTail = "";
                return;
            }

            addressInput.value = value + tail;
            lastTail = tail;
        }

        /*
        | On a submit that came back with errors the address already holds
        | whatever was sent, tail included. Remembering it means the next
        | change replaces that tail instead of stacking a second one.
        */
        if (addressInput && addressInput.value.trim() !== "") {
            var initial = suffix();
            if (initial !== "" && addressInput.value.slice(-initial.length) === initial) {
                lastTail = initial;
            }
        }

        provinceInput.addEventListener("change", function () {
            fillCities(provinceInput.value, false);
            syncAddress();
        });

        cityInput.addEventListener("change", syncAddress);

        /* Typing the street first, then tabbing out, picks the tail up too. */
        if (addressInput) {
            addressInput.addEventListener("blur", function () {
                if (lastTail === "") syncAddress();
            });
        }

    });
</script>

<script>
    /*
    | A TIN is grouped 000-000-000-00000 on the BIR certificate, so the field
    | groups it the same way as it is typed. That makes it readable against
    | the paper, which is the whole point of showing it back.
    |
    | The server normalises it again regardless; this only keeps the field
    | honest about what it accepted.
    */
    (function () {
        var tin = document.querySelector('input[name="bir_tin"]');

        if (!tin) return;

        tin.addEventListener('input', function () {
            var digits = tin.value.replace(/[^0-9]/g, '').slice(0, 14);
            var parts = [];

            if (digits.length) parts.push(digits.slice(0, 3));
            if (digits.length > 3) parts.push(digits.slice(3, 6));
            if (digits.length > 6) parts.push(digits.slice(6, 9));
            if (digits.length > 9) parts.push(digits.slice(9));

            var grouped = parts.join('-');

            if (tin.value !== grouped) {
                tin.value = grouped;
            }
        });
    })();
</script>

<!--
    tesseract.js reads the certificate in the browser. The document is never
    sent anywhere to be read: it reaches our server once, as the attachment,
    and nowhere else.
-->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script src="<?= $BASE_URL ?>/assets/js/permit-ocr.js"></script>


<script>
    /*
    | Size checks before the upload starts.
    |
    | The server check stays authoritative -- this one is trivially bypassed.
    | It exists so an owner hears "too large" the instant they pick a 15 MB
    | photo, instead of after uploading it in full; and so the form never
    | sends more than post_max_size, which is what used to wipe every field.
    |
    | A staged file counts toward the total only while no new file replaces it.
    */
    (function () {
        var form = document.querySelector('input[type="file"][data-max-bytes]');
        form = form ? form.closest("form") : null;
        if (!form) return;

        var inputs = form.querySelectorAll('input[type="file"][data-max-bytes]');
        var TOTAL_LIMIT = 20 * 1024 * 1024;

        function mb(bytes) {
            return (bytes / 1048576).toFixed(2) + " MB";
        }

        function clearError(input) {
            var next = input.parentNode.querySelector('.file-size-error[data-for="' + input.name + '"]');
            if (next) next.remove();
        }

        function showError(input, text) {
            clearError(input);
            var div = document.createElement("div");
            div.className = "file-size-error";
            div.setAttribute("data-for", input.name);
            div.textContent = text;
            input.insertAdjacentElement("afterend", div);
        }

        inputs.forEach(function (input) {
            input.addEventListener("change", function () {
                clearError(input);
                var file = input.files && input.files[0];
                if (!file) return;

                var max = parseInt(input.getAttribute("data-max-bytes"), 10);
                if (file.size > max) {
                    showError(input, file.name + " is " + mb(file.size) + ". Each document must be 5 MB or smaller.");
                    input.value = "";
                }
            });
        });

        form.addEventListener("submit", function (event) {
            var total = 0;
            var tooBig = false;

            inputs.forEach(function (input) {
                var file = input.files && input.files[0];
                if (!file) return;

                var max = parseInt(input.getAttribute("data-max-bytes"), 10);
                if (file.size > max) {
                    showError(input, file.name + " is " + mb(file.size) + ". Each document must be 5 MB or smaller.");
                    tooBig = true;
                }
                total += file.size;
            });

            if (tooBig || total > TOTAL_LIMIT) {
                event.preventDefault();

                if (!tooBig) {
                    alert("Your documents add up to " + mb(total) + ". Together they must be 20 MB or less.");
                }

                var firstError = form.querySelector(".file-size-error");
                if (firstError) firstError.scrollIntoView({ behavior: "smooth", block: "center" });
            }
        });
    })();


    /*
    | BUSINESS NAME
    |
    | Mirrors the server rule so the owner is told before the upload starts,
    | rather than after a full submit. The server check stays authoritative.
    */
    (function () {
        var input = document.querySelector('input[name="company_name"]');
        var form = input ? input.closest("form") : null;
        if (!form) return;

        var feedback = input.parentNode.querySelector(".invalid-feedback");

        function nameHasLetter(value) {
            // Unicode letters, to match the server's \p{L}. Older browsers
            // without the u-flag property escape fall back to ASCII.
            try {
                return new RegExp("\\p{L}", "u").test(value);
            } catch (error) {
                return /[a-z]/i.test(value);
            }
        }

        function reject(message) {
            input.classList.add("is-invalid");
            if (feedback) feedback.textContent = message;
        }

        input.addEventListener("input", function () {
            input.classList.remove("is-invalid");
        });

        form.addEventListener("submit", function (event) {
            var value = input.value.trim();

            if (value === "") {
                return; // "required" is the server's message to give.
            }

            if (!nameHasLetter(value)) {
                event.preventDefault();
                reject("Business name must include letters, not numbers only.");
                input.scrollIntoView({ behavior: "smooth", block: "center" });
                input.focus();
            }
        });
    })();
</script>


<!--
    The shared guard: validation against the same rules the server applies,
    then one confirmation before the application is sent. resubmit.php uses
    the same file, so the two forms cannot drift apart.
-->
<script src="<?= $BASE_URL ?>/assets/js/public-form-guard.js"></script>

<?php include __DIR__ . "/footer.php"; ?>