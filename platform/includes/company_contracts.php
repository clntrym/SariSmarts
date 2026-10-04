<?php

/*
|--------------------------------------------------------------------------
| THE SERVICE AGREEMENT A BUSINESS SIGNS
|--------------------------------------------------------------------------
|
| Separate from platform_employee_contracts, which is an employment
| contract between RetailCore and a member of its own staff. This one is
| between RetailCore and a customer, and it is issued when they avail a
| subscription.
|
|   Issued   they have it, nothing back yet
|   Signed   their signed copy is uploaded
|
| and alongside it, what the Super Admin made of what came back:
|
|   Pending / Approved / Rejected
|
| WHERE THE FILES LIVE
|
| uploads/company_contracts, which denies everything. Two doors open onto
| it and they ask different questions:
|
|   superAdmin/company_contract_file.php  may this staff member read it?
|   subscribe.php                         is this the business it belongs to?
|
| A business can only ever reach its own, because subscribe.php has already
| checked the email and password against that company before it serves
| anything.
|
*/

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/platform_files.php';

if (!defined('COMPANY_CONTRACT_MAX_BYTES')) {
    define('COMPANY_CONTRACT_MAX_BYTES', 5 * 1024 * 1024);
}

if (!defined('COMPANY_CONTRACT_DIR')) {
    define('COMPANY_CONTRACT_DIR', dirname(__DIR__) . '/uploads/company_contracts');
}


if (!function_exists('contractTemplate')) {

    /*
    | The blank template the Super Admin uploaded, or null when none has
    | been set yet.
    |
    | @return array{path:string,name:string}|null
    */
    function contractTemplate(mysqli $conn): ?array
    {
        $row = $conn->query("
            SELECT contract_template, contract_template_name
            FROM platform_settings
            ORDER BY setting_id LIMIT 1
        ");

        $row = $row ? $row->fetch_assoc() : null;

        if (!$row || trim((string) $row['contract_template']) === '') {
            return null;
        }

        return [
            'path' => $row['contract_template'],
            'name' => $row['contract_template_name'] ?: 'Service Agreement.pdf',
        ];
    }
}


if (!function_exists('issueCompanyContract')) {

    /*
    | Issues the agreement for a subscription just availed.
    |
    | Returns the contract number, or null when one already exists for this
    | subscription -- choosing the same plan twice must not produce two
    | agreements, and the caller only announces a number when there is a
    | new one.
    |
    | The template is COPIED onto the row rather than pointed at. The Super
    | Admin can replace the blank whenever the terms change, and that must
    | not rewrite what a business already agreed to.
    |
    | A missing template is not an error here. The contract is still issued
    | and the business is told it is being prepared; the alternative is
    | blocking somebody from subscribing because an administrator has not
    | uploaded a file yet.
    */
    function issueCompanyContract(mysqli $conn, int $companyId, ?int $subscriptionId): ?string
    {
        if ($subscriptionId !== null) {
            $check = $conn->prepare("
                SELECT contract_id FROM company_contracts
                WHERE subscription_id = ? LIMIT 1
            ");
            $check->bind_param("i", $subscriptionId);
            $check->execute();
            $exists = $check->get_result()->num_rows > 0;
            $check->close();

            if ($exists) {
                return null;
            }
        }

        $number = 'AGR-' . date('Ymd') . '-' . str_pad((string) $companyId, 5, '0', STR_PAD_LEFT);

        /* Same company, same day, a second subscription: keep it unique. */
        $suffix = 0;
        $candidate = $number;

        while (true) {
            $check = $conn->prepare("SELECT contract_id FROM company_contracts WHERE contract_number = ? LIMIT 1");
            $check->bind_param("s", $candidate);
            $check->execute();
            $taken = $check->get_result()->num_rows > 0;
            $check->close();

            if (!$taken) {
                break;
            }

            $suffix++;
            $candidate = $number . '-' . $suffix;
        }

        $template = contractTemplate($conn);
        $issued = $template['path'] ?? null;

        $stmt = $conn->prepare("
            INSERT INTO company_contracts
                (company_id, subscription_id, contract_number, issued_template)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("iiss", $companyId, $subscriptionId, $candidate, $issued);
        $stmt->execute();
        $stmt->close();

        return $candidate;
    }
}


if (!function_exists('contractForCompany')) {

    /*
    | The agreement a business is currently being asked to sign, newest
    | first. A company that renewed has more than one; the one that matters
    | on screen is the latest.
    */
    function contractForCompany(mysqli $conn, int $companyId): ?array
    {
        $stmt = $conn->prepare("
            SELECT * FROM company_contracts
            WHERE company_id = ?
            ORDER BY contract_id DESC
            LIMIT 1
        ");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
}


if (!function_exists('fillMissingTemplate')) {

    /*
    | Gives a copy to an agreement that was never given one.
    |
    | issueCompanyContract() does not refuse to issue when no template has
    | been uploaded yet -- blocking somebody from subscribing because an
    | administrator has not uploaded a file would be the wrong failure. But
    | the row it writes has no issued_template, and without this it would
    | read "being prepared" forever even after a template arrived.
    |
    | This is not the same as re-snapshotting. An agreement that HAS a copy
    | keeps it, so changing the terms tomorrow still cannot rewrite what
    | somebody agreed to yesterday. Only the never-issued are filled in, and
    | only once.
    |
    | Returns the row, with the template on it if one was just attached.
    */
    function fillMissingTemplate(mysqli $conn, ?array $contract): ?array
    {
        if (!$contract) {
            return $contract;
        }

        $held = trim((string) $contract['issued_template']);

        /*
        | A path is not a copy.
        |
        | Agreements issued before the bytes were kept in the database point
        | at files Render wiped on its next deploy. The row is not empty, so
        | this returned early and the business was shown "That file is no
        | longer on record" with nothing it could do about it.
        |
        | The rule above is unchanged -- an agreement that HAS a copy keeps
        | it. A row whose copy cannot be retrieved from the store or from
        | the disk does not have one, and is re-issued the current template
        | rather than left pointing at nothing.
        */
        if ($held !== '') {

            $missing = platformFileRead($conn, $held) === null
                && !is_file(dirname(__DIR__) . '/' . $held);

            if (!$missing) {
                return $contract;
            }
        }

        /* Nothing to give it yet. */
        $template = contractTemplate($conn);

        if (!$template) {
            return $contract;
        }

        /*
        | Guarded on the row still holding what we read, so two requests
        | arriving together cannot both write and the second cannot
        | overwrite a copy the first just handed out.
        |
        | The guard was "still empty". It now names the exact value, because
        | a row being repaired is not empty -- it holds the dead path.
        */
        $stmt = $conn->prepare("
            UPDATE company_contracts
            SET issued_template = ?
            WHERE contract_id = ?
              AND (issued_template IS NULL OR issued_template = '' OR issued_template = ?)
        ");
        $stmt->bind_param("sis", $template['path'], $contract['contract_id'], $held);
        $stmt->execute();
        $stmt->close();

        $contract['issued_template'] = $template['path'];

        return $contract;
    }
}


if (!function_exists('storeCompanyContractFile')) {

    /*
    | Moves an uploaded PDF into place and returns its path, or a problem
    | as a sentence.
    |
    | The type is read from the file's own bytes with finfo, not from its
    | name. The stored name is generated here, so nothing the uploader
    | chose is ever used to build a path.
    |
    | @return array{0: ?string, 1: ?string} [relative path, problem]
    */
    function storeCompanyContractFile(array $file, string $prefix, string $kind): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [null, 'Please choose the signed contract file.'];
        }

        /*
        | Which refusal it was. Seven codes read as one sentence before, and
        | two of them -- no temporary folder, and a failed write -- are about
        | the server, not the document somebody is being asked to re-check.
        */
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [null, uploadErrorMessage((int) $file['error'])];
        }

        if ($file['size'] > COMPANY_CONTRACT_MAX_BYTES) {
            return [null, 'The file is larger than 5 MB.'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);

        if ($finfo->file($file['tmp_name']) !== 'application/pdf') {
            return [null, 'The signed contract must be a PDF.'];
        }

        $name = $prefix . '_' . $kind . '_' . bin2hex(random_bytes(8)) . '.pdf';
        $relative = 'uploads/company_contracts/' . $name;

        /*
        | The bytes go to the database, which is the only durable thing this
        | deployment has: Render's free instances keep no disk between
        | deploys, so a file written here is gone by the time the Super Admin
        | opens it, leaving a row pointing at nothing.
        |
        | The returned path is unchanged -- the callers and the columns still
        | hold the same string, and it is now a key rather than a location.
        */
        $bytes = @file_get_contents($file['tmp_name']);

        if ($bytes === false) {
            return [null, 'The uploaded file could not be read.'];
        }

        if (!platformFileStore($GLOBALS['conn'], $relative, $bytes, 'application/pdf',
                               (string) ($file['name'] ?? $name))) {
            return [null, 'Could not store the file.'];
        }

        /*
        | And to the disk as well, where there is one. Harmless on Render,
        | where it disappears; on XAMPP it keeps the uploads folder looking
        | the way the rest of the project expects.
        */
        if (is_dir(COMPANY_CONTRACT_DIR) || @mkdir(COMPANY_CONTRACT_DIR, 0777, true)) {
            @move_uploaded_file($file['tmp_name'], COMPANY_CONTRACT_DIR . '/' . $name);
        }

        return [$relative, null];
    }
}


if (!function_exists('subscriptionReadiness')) {

    /*
    |--------------------------------------------------------------------------
    | WHAT STILL STANDS BETWEEN A BUSINESS AND PAYING
    |--------------------------------------------------------------------------
    |
    | The system decides this, not the order the screens happen to appear in.
    | Payment used to open the moment a plan was confirmed -- the business was
    | on the PayMongo page before it had seen the agreement, let alone signed
    | it. Nothing enforced the sequence; the sequence was just what the code
    | happened to do next.
    |
    | So every condition is named here and checked on every request. A screen
    | that forgets to ask, or a form posted straight at the handler, meets the
    | same list.
    |
    | Returns every step with whether it is done, so the page can show the
    | whole path rather than only the next obstacle. "ready" is true when
    | nothing is outstanding.
    |
    | @return array{ready:bool, steps:array<int, array{key:string,label:string,done:bool,detail:string}>}
    */
    function subscriptionReadiness(mysqli $conn, ?array $company, ?array $subscription, ?array $contract): array
    {
        $steps = [];

        $add = function (string $key, string $label, bool $done, string $detail = '') use (&$steps): void {
            $steps[] = ['key' => $key, 'label' => $label, 'done' => $done, 'detail' => $detail];
        };


        /* The business itself must be through review. */
        $approved = $company && in_array($company['status'], ['Approved', 'Active'], true);

        $add('approved', 'Business approved', $approved,
             $approved ? '' : 'Our team is still reviewing your registration.');


        /*
        | The details that end up on the agreement. A contract signed with
        | half its boxes empty is not worth filing, and these are the ones
        | the system can see for itself.
        */
        $required = [
            'company_name' => 'business name',
            'address'      => 'business address',
            'email'        => 'business email',
            'phone'        => 'contact number',
            'tin_number'   => 'TIN',
            'owner_name'   => 'authorised representative',
        ];

        $missing = [];

        foreach ($required as $field => $label) {
            if (!$company || trim((string) ($company[$field] ?? '')) === '') {
                $missing[] = $label;
            }
        }

        $add('details', 'Business details complete', $missing === [],
             $missing === [] ? '' : 'Still needed: ' . implode(', ', $missing) . '.');


        /* A plan has to have been chosen for there to be anything to sign. */
        $hasPlan = $subscription !== null;

        $add('plan', 'Subscription chosen', $hasPlan,
             $hasPlan ? '' : 'Choose a plan first.');


        /* The agreement: issued, signed, accepted. Three separate facts. */
        $issued = $contract !== null;

        $add('issued', 'Agreement issued', $issued,
             $issued ? '' : 'Your agreement is being prepared.');

        $signedBack = $issued && $contract['status'] === 'Signed';

        $add('signed', 'Signed agreement received', $signedBack,
             $signedBack ? '' : 'Download the agreement, sign it, and send it back.');

        $accepted = $issued && $contract['review'] === 'Approved';

        $add('accepted', 'Agreement accepted', $accepted,
             $accepted ? ''
                 : ($issued && $contract['review'] === 'Rejected'
                     ? 'Our team could not accept the copy you sent.'
                     : 'Our team is reading your signed agreement.'));


        $ready = true;

        foreach ($steps as $step) {
            if (!$step['done']) {
                $ready = false;
                break;
            }
        }

        return ['ready' => $ready, 'steps' => $steps];
    }
}


if (!function_exists('sendContractFile')) {

    /*
    | Streams one contract file to the caller.
    |
    | Callers decide who may read; this only decides that the path resolves
    | inside the contracts folder. A row that points somewhere else, or at a
    | file somebody deleted, is a 404 rather than a warning printed into the
    | response body.
    */
    function sendContractFile(?string $relative, string $downloadName, string $disposition = 'inline'): void
    {
        $relative = trim((string) $relative);

        if ($relative === '') {
            http_response_code(404);
            exit('No file has been attached to that contract yet.');
        }

        /*
        | The store first, the disk second.
        |
        | A business that was approved saw "That file is no longer on record"
        | on an agreement whose row was perfectly intact: realpath() returned
        | false because Render had wiped the PDF on the last deploy. The
        | bytes live in the database now, and the path is the key to them.
        |
        | The disk is still consulted afterwards, so a file uploaded before
        | this change -- on XAMPP, where it is still there -- goes on being
        | served.
        */
        $stored = platformFileRead($GLOBALS['conn'], $relative);

        if ($stored !== null) {

            $disposition = $disposition === 'attachment' ? 'attachment' : 'inline';

            header('Content-Type: ' . ($stored['mime'] ?: 'application/pdf'));
            header('Content-Length: ' . strlen($stored['bytes']));
            header('Content-Disposition: ' . $disposition . '; filename="' . $downloadName . '"');
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');

            echo $stored['bytes'];
            exit;
        }

        $real = realpath(dirname(__DIR__) . '/' . $relative);
        $base = realpath(COMPANY_CONTRACT_DIR);
        $blank = realpath(dirname(__DIR__) . '/uploads');

        /* The blank template lives one level up, in uploads/. */
        $inside = $real !== false
            && (($base !== false && strpos($real, $base) === 0)
                || ($blank !== false && strpos($real, $blank) === 0));

        if (!$inside) {
            http_response_code(404);
            exit('That file is no longer on record.');
        }

        /*
        | inline opens it in the browser's PDF viewer; attachment saves it.
        |
        | They are not interchangeable. With inline, Chrome and Brave show
        | the document but their viewer's own download button names the file
        | after the URL -- so "Download the agreement" saved subscribe.php,
        | as a PHP file, which is neither the name nor the type.
        |
        | So a link that says download asks for attachment, and only a
        | preview asks for inline.
        */
        $disposition = $disposition === 'attachment' ? 'attachment' : 'inline';

        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($real));
        header('Content-Disposition: ' . $disposition . '; filename="' . $downloadName . '"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');

        readfile($real);
        exit;
    }
}
