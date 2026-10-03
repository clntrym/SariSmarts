<?php

require_once __DIR__ . "/init.php";
require_once __DIR__ . "/includes/permits.php";
require_once __DIR__ . "/includes/field_rules.php";

/*
|--------------------------------------------------------------------------
| RESUBMIT A REJECTED APPLICATION
|--------------------------------------------------------------------------
|
| A rejected owner cannot sign into the platform - the login gate stops
| them - so this page carries its own email + password check. It only ever
| unlocks this one form, never the app.
|
| Correcting the details puts the company back to Pending and records a
| 'Resubmitted' entry, so the review history shows every round trip.
|
*/

$businessTypes = ['Retail Store', 'Wholesale', 'Supermarket', 'Convenience Store', 'Franchise', 'Other'];
$businessSizes = ['Micro', 'Small', 'Medium', 'Large'];

$assetRanges = [
    'Up to PHP 3,000,000',
    'PHP 3,000,001 - PHP 15,000,000',
    'PHP 15,000,001 - PHP 100,000,000',
    'Above PHP 100,000,000',
];

const MAX_DOCUMENT_BYTES = 5 * 1024 * 1024;

$allowedDocuments = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
];

$errors = [];
$company = null;
$done = false;


/*
|--------------------------------------------------------------------------
| UNLOCK
|--------------------------------------------------------------------------
*/

function loadRejectedCompany($conn, $email, $password)
{
    $stmt = $conn->prepare("
        SELECT u.user_id, u.password, u.company_id, c.*
        FROM users u
        INNER JOIN company c ON c.company_id = u.company_id
        WHERE u.email = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($password, $row['password'])) {
        return null;
    }

    return $row;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $company = loadRejectedCompany($conn, $email, $password);

    if (!$company) {
        $errors['auth'] = 'Email or password is incorrect.';
    } elseif ($company['status'] !== 'Rejected') {
        $errors['auth'] = 'This application is not awaiting changes. Its status is ' . $company['status'] . '.';
        $company = null;
    }
}


/*
|--------------------------------------------------------------------------
| SAVE THE CORRECTIONS
|--------------------------------------------------------------------------
*/

if ($company && isset($_POST['resubmit'])) {

    $companyName  = trim($_POST['company_name'] ?? '');
    $businessType = trim($_POST['business_type'] ?? '');
    $address      = trim($_POST['address'] ?? '');
    $city         = trim($_POST['city'] ?? '');
    $province     = trim($_POST['province'] ?? '');
    $postalCode   = trim($_POST['postal_code'] ?? '');
    /*
    | DTI and BIR, the same two certificates registration now asks for. The
    | DTI expiry is computed, never typed, so a correction to the
    | registration date moves the expiry with it instead of leaving the two
    | disagreeing.
    */
    $dtiNumber = preg_replace('/\D/', '', (string) ($_POST['dti_registration_number'] ?? ''));
    $dtiDate   = trim($_POST['dti_registration_date'] ?? '');
    $dtiExpiry = dtiExpiryFrom($dtiDate);

    $birTin  = normaliseTin($_POST['bir_tin'] ?? '');
    $birDate = trim($_POST['bir_registration_date'] ?? '');
    $birRdo  = preg_replace('/\D/', '', (string) ($_POST['bir_rdo_code'] ?? ''));
    $birOcn  = trim($_POST['bir_ocn'] ?? '');

    $tin = $birTin ?? '';

    $dbToday = $conn->query('SELECT CURDATE() AS d')->fetch_assoc()['d'];
    $branches     = trim($_POST['number_of_branches'] ?? '');
    $employees    = trim($_POST['estimated_employees'] ?? '');
    $assetRange   = trim($_POST['business_asset_range'] ?? '');
    $businessSize = trim($_POST['business_size'] ?? '');

    /*
    | The same character rules registration applies, from field_rules.php, so
    | a correction cannot slip in what the original form would have refused.
    |
    | fieldProblem returns null when a field is fine, and those nulls are
    | filtered out below -- an $errors array full of nulls is not empty, and
    | would hold a valid resubmission back forever.
    */
    $errors['company_name'] = fieldProblem(
        $companyName,
        'Business name',
        RULE_BUSINESS,
        true,
        150,
        "letters, numbers, spaces and . , & ' ( ) -"
    );

    $errors['address'] = fieldProblem(
        $address,
        'Business address',
        RULE_ADDRESS,
        true,
        255,
        "letters, numbers, spaces and . , & ' ( ) # / -"
    );

    $errors['city'] = fieldProblem(
        $city,
        'City or municipality',
        RULE_PLACE,
        true,
        120,
        "letters, spaces and . , ' ( ) -"
    );

    $errors['postal_code'] = fieldProblem(
        $postalCode,
        'Postal code',
        RULE_POSTAL,
        false,
        4,
        'four digits'
    );

    if (!in_array($businessType, $businessTypes, true)) {
        $errors['business_type'] = 'Please choose a business type.';
    }

    /* One rule set, shared with registration and the Super Admin review. */
    $errors += permitProblem($_POST, $dbToday);

    if ($branches === '' || !ctype_digit($branches) || (int) $branches < 1) {
        $errors['number_of_branches'] = 'Enter the number of branches.';
    }

    if ($employees === '' || !ctype_digit($employees) || (int) $employees < 1) {
        $errors['estimated_employees'] = 'Enter an estimated number of employees.';
    }

    if (!in_array($assetRange, $assetRanges, true)) {
        $errors['business_asset_range'] = 'Please choose an asset range.';
    }

    if (!in_array($businessSize, $businessSizes, true)) {
        $errors['business_size'] = 'Please choose a business size.';
    }


    /*
    | Each permit number has its own document. Replacements are all optional --
    | an application is usually rejected over one of them, and re-uploading
    | three correct files to fix a fourth would be busywork. Whatever is left
    | blank keeps the file already on record.
    */
    $permitDocumentFields = [
        'dti_sec_document' => 'DTI Certificate of Business Name Registration',
        'tin_document'     => 'BIR Certificate of Registration (Form 2303)',
    ];

    $replacementUploads = [];
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    foreach ($permitDocumentFields as $field => $label) {

        $file = $_FILES[$field] ?? null;

        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[$field] = 'The file could not be uploaded.';
            continue;
        }

        if ($file['size'] > MAX_DOCUMENT_BYTES) {
            $errors[$field] = 'File is too large. Maximum size is 5 MB.';
            continue;
        }

        $detected = $finfo->file($file['tmp_name']);

        if (!isset($allowedDocuments[$detected])) {
            $errors[$field] = 'Only PDF, JPG or PNG files are accepted.';
            continue;
        }

        $replacementUploads[$field] = [
            'tmp'       => $file['tmp_name'],
            'extension' => $allowedDocuments[$detected],
        ];
    }


    /*
    | fieldProblem returns null for a field that is fine, and a null entry
    | still counts: empty($errors) would be false on a perfectly good
    | resubmission and nothing would ever save. Strip them before the gate.
    |
    | This sits above both gates. Between them only real messages are added,
    | so filtering once here covers both.
    */
    $errors = array_filter($errors, static function ($message) {
        return $message !== null && $message !== '';
    });


    if (empty($errors)) {

        // Start from what is already on record; only attached files change.
        $documentPaths = [];

        foreach ($permitDocumentFields as $field => $label) {
            $documentPaths[$field] = $company[$field] ?? null;
        }

        $supersededFiles = [];

        foreach ($replacementUploads as $field => $upload) {

            $name = $company['company_code'] . '_' . $field . '_'
                . bin2hex(random_bytes(8)) . '.' . $upload['extension'];

            $target = __DIR__ . '/uploads/business_documents/' . $name;

            if (!move_uploaded_file($upload['tmp'], $target)) {
                $errors[$field] = 'Could not store the replacement document.';
                break;
            }

            /*
            | The old file is remembered rather than deleted here. If the
            | UPDATE below fails, the row still points at it, and deleting it
            | now would leave the company with a path to nothing.
            */
            if (!empty($company[$field])) {
                $supersededFiles[] = __DIR__ . '/' . $company[$field];
            }

            $documentPaths[$field] = 'uploads/business_documents/' . $name;
        }

        /*
        | supporting_document is the original single upload, which older code
        | still reads. The DTI certificate stands in for it now that the
        | business registration document is no longer collected; whatever is
        | already on record is kept when no new DTI file was attached.
        */
        $documentPath = $documentPaths['dti_sec_document'] ?: $company['supporting_document'];
    }


    if (empty($errors)) {

        $conn->begin_transaction();

        try {

            $stmt = $conn->prepare("
                UPDATE company SET
                    company_name = ?, business_type = ?, address = ?, city = ?, province = ?,
                    postal_code = ?, supporting_document = ?,
                    number_of_branches = ?, estimated_employees = ?, business_asset_range = ?,
                    business_size = ?,
                    dti_sec_registration = ?, dti_registration_date = ?, dti_expiry_date = ?,
                    dti_sec_document = ?,
                    tin_number = ?, bir_registration_date = ?, bir_rdo_code = ?, bir_ocn = ?,
                    tin_document = ?,
                    status = 'Pending', review_reason = NULL,
                    reviewed_by = NULL, reviewed_at = NULL, submitted_at = NOW()
                WHERE company_id = ?
            ");

            $branchCount = (int) $branches;
            $employeeCount = (int) $employees;
            $companyId = (int) $company['company_id'];

            $dtiDocument = $documentPaths['dti_sec_document'];
            $tinDocument = $documentPaths['tin_document'];

            /* Empty means "not given", which is null, not an empty string. */
            $rdoValue = $birRdo === '' ? null : $birRdo;
            $ocnValue = $birOcn === '' ? null : $birOcn;

            $stmt->bind_param(
                "sssssssiisssssssssssi",
                $companyName, $businessType, $address, $city, $province,
                $postalCode, $documentPath,
                $branchCount, $employeeCount, $assetRange, $businessSize,
                $dtiNumber, $dtiDate, $dtiExpiry, $dtiDocument,
                $tin, $birDate, $rdoValue, $ocnValue, $tinDocument, $companyId
            );
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("
                INSERT INTO company_review_history (company_id, action, reason)
                VALUES (?, 'Resubmitted', ?)
            ");
            $note = 'Corrections submitted by the owner.';
            $stmt->bind_param("is", $companyId, $note);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            $done = true;

        } catch (Throwable $e) {

            $conn->rollback();
            $errors['general'] = 'Could not save your changes. Please try again.';
        }
    }
}


include("header.php");

$value = function ($key, $fallback = '') use ($company) {
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    return $company[$fallback ?: $key] ?? '';
};

?>

<link rel="stylesheet" href="<?= $BASE_URL ?>/assets/css/permits.css">

<style>
    .rs-wrap { background:#f5f7fb; padding:48px 0; }
    .rs-card { max-width:900px; margin:0 auto; background:#fff; border-radius:20px;
               box-shadow:0 12px 40px rgba(0,0,0,.08); overflow:hidden; }
    .rs-head { background:#00224c; color:#fff; padding:32px 40px; }
    .rs-head h1 { font-size:24px; font-weight:700; margin:0; }
    .rs-head p { color:#cbd5e1; margin:8px 0 0; }
    .rs-body { padding:32px 40px 40px; }
    .section-label { font-weight:700; color:#00224c; margin:26px 0 14px; font-size:15px;
                     padding-bottom:10px; border-bottom:1px solid #eef2f6; }
</style>


<div class="rs-wrap">

    <div class="rs-card">

        <div class="rs-head">
            <h1>Update your application</h1>
            <p>Correct the details our team flagged, then submit again for review.</p>
        </div>

        <div class="rs-body">

            <?php if ($done): ?>

                <div class="text-center py-4">
                    <div style="font-size:56px;color:#198754;"><i class="bi bi-send-check"></i></div>
                    <h3 class="fw-bold mt-3" style="color:#00224c;">Changes submitted</h3>
                    <p class="text-muted">
                        Your application is back in the review queue. We will email you once
                        it has been reviewed again.
                    </p>
                    <a href="index.php" class="btn text-white mt-2" style="background:#00224c;">Back to Home</a>
                </div>

            <?php elseif (!$company): ?>

                <?php if (!empty($errors['auth'])): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($errors['auth']) ?></div>
                <?php endif; ?>

                <p class="text-muted">
                    Sign in with the email and password you registered with.
                </p>

                <form method="POST" class="mt-3">

                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>

                    <button type="submit" class="btn text-white w-100 py-2" style="background:#00224c;">
                        Continue
                    </button>

                </form>

            <?php else: ?>

                <div class="alert alert-danger">
                    <strong>What our team asked you to correct</strong><br>
                    <?= nl2br(htmlspecialchars($company['review_reason'] ?: 'Please review your details.')) ?>
                </div>

                <?php if (!empty($errors['general'])): ?>
                    <div class="alert alert-warning"><?= htmlspecialchars($errors['general']) ?></div>
                <?php endif; ?>

                <?php /* Guarded by assets/js/public-form-guard.js, as register.php is. */ ?>
                <form method="POST" enctype="multipart/form-data" id="resubmitForm" novalidate
                    data-guard
                    data-confirm-title="Send these changes for review?"
                    data-confirm-text="Your application goes back to Pending and a reviewer reads it again. You will be emailed the result."
                    data-confirm-ok="Yes, send">

                    <input type="hidden" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    <input type="hidden" name="password" value="<?= htmlspecialchars($_POST['password'] ?? '') ?>">

                    <div class="section-label">Business Information</div>

                    <div class="row g-3">

                        <div class="col-md-7">
                            <label class="form-label">Business Name</label>
                            <input type="text" name="company_name" maxlength="150" required
                                pattern="<?= htmlspecialchars(RULE_BUSINESS) ?>"
                                title="Letters, numbers, spaces and . , &amp; ' ( ) - only."
                                class="form-control <?= isset($errors['company_name']) ? 'is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($value('company_name')) ?>">
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['company_name'] ?? '') ?></div>
                        </div>

                        <div class="col-md-5">
                            <label class="form-label">Business Type</label>
                            <select name="business_type" class="form-select">
                                <?php foreach ($businessTypes as $type): ?>
                                    <option <?= $value('business_type') === $type ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($type) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Business Address</label>
                            <?php /* data-pattern, not pattern: a textarea has none. */ ?>
                            <textarea name="address" rows="2" maxlength="255" required
                                data-pattern="<?= htmlspecialchars(RULE_ADDRESS) ?>"
                                title="Letters, numbers, spaces and . , &amp; ' ( ) # / - only."
                                class="form-control <?= isset($errors['address']) ? 'is-invalid' : '' ?>"><?= htmlspecialchars($value('address')) ?></textarea>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['address'] ?? '') ?></div>
                        </div>

                        <div class="col-md-5">
                            <label class="form-label">City</label>
                            <input type="text" name="city" maxlength="120" required
                                pattern="<?= htmlspecialchars(RULE_PLACE) ?>"
                                title="Letters, spaces and . , ' ( ) - only."
                                class="form-control <?= isset($errors['city']) ? 'is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($value('city')) ?>">
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['city'] ?? '') ?></div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Province</label>
                            <input type="text" name="province" maxlength="120" class="form-control"
                                value="<?= htmlspecialchars($value('province')) ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Postal Code</label>
                            <input type="text" name="postal_code" maxlength="4" inputmode="numeric"
                                pattern="<?= htmlspecialchars(RULE_POSTAL) ?>" title="Four digits."
                                class="form-control <?= isset($errors['postal_code']) ? 'is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($value('postal_code')) ?>">
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['postal_code'] ?? '') ?></div>
                        </div>

                    </div>


                    <div class="section-label">Registration &amp; Permits</div>

                    <p class="text-muted small mb-3">
                        Attach a file only to replace the one already on record. Choose a file
                        and we will read the details off it, then check what we read against
                        the paper. PDF, JPG or PNG, maximum 5 MB each.
                    </p>


                    <!-- ===== DTI ===================================== -->

                    <div class="permit-block" data-permit-ocr="dti">

                        <div class="permit-block-head">
                            <span><i class="bi bi-file-earmark-text me-2"></i>DTI Certificate of Business Name Registration</span>
                            <span class="permit-block-note">Valid 5 years</span>
                        </div>

                        <div class="row g-3">

                            <div class="col-12">
                                <label class="form-label">Replace certificate file</label>
                                <input type="file" name="dti_sec_document" accept=".pdf,.jpg,.jpeg,.png"
                                    class="form-control <?= isset($errors['dti_sec_document']) ? 'is-invalid' : '' ?>">
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['dti_sec_document'] ?? '') ?></div>
                                <?php if (!empty($company['dti_sec_document'])): ?>
                                    <div class="form-text">
                                        <a href="<?= htmlspecialchars($company['dti_sec_document']) ?>"
                                            target="_blank" rel="noopener">View the file already on record</a>
                                    </div>
                                <?php endif; ?>
                                <div class="permit-ocr-note" data-ocr-note hidden></div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Business Name No. <span class="text-danger">*</span></label>
                                <input type="text" name="dti_registration_number" inputmode="numeric" maxlength="10"
                                    placeholder="e.g. 4955922"
                                    class="form-control <?= isset($errors['dti_registration_number']) ? 'is-invalid' : '' ?>"
                                    value="<?= htmlspecialchars($value('dti_registration_number') !== '' ? $value('dti_registration_number') : ($company['dti_sec_registration'] ?? '')) ?>">
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['dti_registration_number'] ?? '') ?></div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Registration date <span class="text-danger">*</span></label>
                                <input type="date" name="dti_registration_date" max="<?= htmlspecialchars($dbToday ?? date('Y-m-d')) ?>"
                                    class="form-control <?= isset($errors['dti_registration_date']) ? 'is-invalid' : '' ?>"
                                    value="<?= htmlspecialchars($value('dti_registration_date') !== '' ? $value('dti_registration_date') : ($company['dti_registration_date'] ?? '')) ?>"
                                    data-dti-registration>
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['dti_registration_date'] ?? '') ?></div>
                                <div class="form-text">The "valid from" date on the certificate.</div>
                            </div>

                            <div class="col-12">
                                <!-- Shown, never typed: five years from the registration date. -->
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
                                <label class="form-label">Replace certificate file</label>
                                <input type="file" name="tin_document" accept=".pdf,.jpg,.jpeg,.png"
                                    class="form-control <?= isset($errors['tin_document']) ? 'is-invalid' : '' ?>">
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['tin_document'] ?? '') ?></div>
                                <?php if (!empty($company['tin_document'])): ?>
                                    <div class="form-text">
                                        <a href="<?= htmlspecialchars($company['tin_document']) ?>"
                                            target="_blank" rel="noopener">View the file already on record</a>
                                    </div>
                                <?php endif; ?>
                                <div class="permit-ocr-note" data-ocr-note hidden></div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">TIN and branch code <span class="text-danger">*</span></label>
                                <input type="text" name="bir_tin" maxlength="20"
                                    placeholder="e.g. 707-046-841-00000"
                                    class="form-control <?= isset($errors['bir_tin']) ? 'is-invalid' : '' ?>"
                                    value="<?= htmlspecialchars($value('bir_tin') !== '' ? $value('bir_tin') : ($company['tin_number'] ?? '')) ?>">
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['bir_tin'] ?? '') ?></div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Registration date <span class="text-danger">*</span></label>
                                <input type="date" name="bir_registration_date" max="<?= htmlspecialchars($dbToday ?? date('Y-m-d')) ?>"
                                    class="form-control <?= isset($errors['bir_registration_date']) ? 'is-invalid' : '' ?>"
                                    value="<?= htmlspecialchars($value('bir_registration_date') !== '' ? $value('bir_registration_date') : ($company['bir_registration_date'] ?? '')) ?>">
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['bir_registration_date'] ?? '') ?></div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">RDO code</label>
                                <input type="text" name="bir_rdo_code" inputmode="numeric" maxlength="3"
                                    placeholder="e.g. 036"
                                    class="form-control <?= isset($errors['bir_rdo_code']) ? 'is-invalid' : '' ?>"
                                    value="<?= htmlspecialchars($value('bir_rdo_code') !== '' ? $value('bir_rdo_code') : ($company['bir_rdo_code'] ?? '')) ?>">
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['bir_rdo_code'] ?? '') ?></div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">OCN</label>
                                <input type="text" name="bir_ocn" maxlength="40"
                                    placeholder="e.g. 036RC20240000002730"
                                    class="form-control <?= isset($errors['bir_ocn']) ? 'is-invalid' : '' ?>"
                                    value="<?= htmlspecialchars($value('bir_ocn') !== '' ? $value('bir_ocn') : ($company['bir_ocn'] ?? '')) ?>">
                                <div class="invalid-feedback"><?= htmlspecialchars($errors['bir_ocn'] ?? '') ?></div>
                            </div>

                        </div>

                    </div>


                    <div class="section-label">Business Size</div>

                    <div class="row g-3">

                        <div class="col-md-3">
                            <label class="form-label">Number of Branches</label>
                            <input type="number" name="number_of_branches" min="1"
                                class="form-control <?= isset($errors['number_of_branches']) ? 'is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($value('number_of_branches')) ?>">
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['number_of_branches'] ?? '') ?></div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Est. Employees</label>
                            <input type="number" name="estimated_employees" min="1"
                                class="form-control <?= isset($errors['estimated_employees']) ? 'is-invalid' : '' ?>"
                                value="<?= htmlspecialchars($value('estimated_employees')) ?>">
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['estimated_employees'] ?? '') ?></div>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Asset Range</label>
                            <select name="business_asset_range" class="form-select">
                                <?php foreach ($assetRanges as $range): ?>
                                    <option <?= $value('business_asset_range') === $range ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($range) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Business Size</label>
                            <select name="business_size" class="form-select">
                                <?php foreach ($businessSizes as $size): ?>
                                    <option <?= $value('business_size') === $size ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($size) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>


                    <button type="submit" name="resubmit" class="btn text-white w-100 mt-4 py-3"
                        style="background:#00224c;">
                        Submit for Review Again
                    </button>

                </form>

            <?php endif; ?>

        </div>

    </div>

</div>

<!--
    tesseract.js reads the certificate in the browser. The document is never
    sent anywhere to be read: it reaches our server once, as the attachment,
    and nowhere else.
-->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script src="<?= $BASE_URL ?>/assets/js/permit-ocr.js"></script>

<!--
    The same guard register.php uses: the pattern and required attributes
    applied by hand, then one confirmation before the changes are sent.
-->
<script src="<?= $BASE_URL ?>/assets/js/public-form-guard.js"></script>

<?php include("footer.php"); ?>
