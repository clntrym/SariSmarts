<?php
/*
|--------------------------------------------------------------------------
| TWO COMPLETE BUSINESSES, READY TO SIGN IN
|--------------------------------------------------------------------------
|
| One on Retail Starter, one on Retail Professional, each with every field a
| real registration fills -- the DTI certificate details, the BIR details, the
| address, the asset range -- and each already approved and paid, so there is
| nothing to click through before logging in.
|
| Why that matters: registration leaves a company 'Pending', its subscription
| 'Pending', and its owner account 'inactive'. That is correct -- the Super
| Admin's approval is what activates them -- but it means a freshly
| registered business cannot be used to test anything. These two skip the
| queue on purpose and say so in their review history.
|
| Run it:
|
|     php tools/seed_demo_companies.php local
|     php tools/seed_demo_companies.php production
|
| Safe to run more than once: a company whose email is already present is
| left alone rather than duplicated.
|
| The document columns hold a filename each. No file is written for them --
| these are demonstration records, and a path to a certificate that was never
| uploaded is more honest as a name than as a broken image.
|
| business_type is an ENUM:
|
|     'Retail Store', 'Wholesale', 'Supermarket',
|     'Convenience Store', 'Franchise', 'Other'
|
| The first version of this file used 'Sole Proprietorship' and
| 'Corporation', which are legal structures rather than shop types and are in
| no such list. MariaDB stored an empty string without complaint; MySQL 8.4
| refused the row outright with "Data truncated for column 'business_type'".
| Two companies therefore existed locally with no type at all, and none at
| all in production -- while two owner accounts were created pointing at
| company 0, because the insert's return value was never checked. Every
| statement below is checked now.
*/

$where = $argv[1] ?? '';

if (!in_array($where, ['local', 'production'], true)) {
    echo "usage: php tools/seed_demo_companies.php <local|production>\n";
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);

if ($where === 'local') {
    $conn = new mysqli('localhost', 'root', '', 'sari');
    $label = 'local XAMPP';
} else {
    $host = getenv('DB_HOST') ?: 'sarismarts-db.mysql.database.azure.com';
    $user = getenv('DB_USER') ?: 'sariAdmin';
    $pass = (string) getenv('DB_PASS');
    $name = getenv('DB_NAME') ?: 'sari';

    if ($pass === '') {
        echo "DB_PASS is not set. Export it, or run this in the Render Shell.\n";
        exit(1);
    }

    $conn = mysqli_init();
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);
    @$conn->real_connect($host, $user, $pass, $name, 3306);
    $label = $host;
}

if ($conn->connect_error) {
    echo "Cannot reach {$label}: {$conn->connect_error}\n";
    exit(1);
}

$conn->set_charset('utf8mb4');

/* The one password both owners use, stated here because a seeded account
   nobody can sign into is not a seeded account. */
const DEMO_PASSWORD = 'Retail@2026';

$businesses = [

    [
        'plan' => 'Retail Starter',
        'code' => 'RC-DEMO-001',
        'name' => 'Aling Nena Sari-Sari Store',
        'type' => 'Retail Store',
        'owner_first' => 'Nenita',
        'owner_middle' => 'Reyes',
        'owner_last' => 'Bautista',
        'email' => 'nenita.bautista@demostore.ph',
        'phone' => '09171234567',
        'address' => '12 Mabini Street, Barangay San Roque',
        'city' => 'Cavite City',
        'province' => 'Cavite',
        'postal' => '4100',
        'branches' => 1,
        'employees' => 4,
        'assets' => 'Below PHP 3,000,000',
        'size' => 'Micro',
        'dti_number' => '4955922',
        'dti_date' => '2023-05-18',
        'dti_expiry' => '2028-05-18',
        'dti_file' => 'demo_dti_starter.jpg',
        'tin' => '707-046-841-00000',
        'bir_date' => '2023-09-11',
        'bir_rdo' => '054',
        'bir_ocn' => '036RC20240000002730',
        'bir_file' => 'demo_bir_starter.jpg',
        'permit_file' => 'demo_permit_starter.jpg',
        'barangay' => 'San Roque',
        'branch_name' => 'Main Store',
    ],

    [
        'plan' => 'Retail Professional',
        'code' => 'RC-DEMO-002',
        'name' => 'Santos Minimart Corporation',
        'type' => 'Convenience Store',
        'owner_first' => 'Ramon',
        'owner_middle' => 'Dela Cruz',
        'owner_last' => 'Santos',
        'email' => 'ramon.santos@santosminimart.ph',
        'phone' => '09228887766',
        'address' => '88 Rizal Avenue, Barangay Poblacion',
        'city' => 'Dasmariñas',
        'province' => 'Cavite',
        'postal' => '4114',
        'branches' => 3,
        'employees' => 26,
        'assets' => 'PHP 15,000,001 to PHP 100,000,000',
        'size' => 'Small',
        'dti_number' => '6712044',
        'dti_date' => '2022-02-03',
        'dti_expiry' => '2027-02-03',
        'dti_file' => 'demo_dti_professional.jpg',
        'tin' => '412-883-205-00001',
        'bir_date' => '2022-03-15',
        'bir_rdo' => '054',
        'bir_ocn' => '054RC20220000009114',
        'bir_file' => 'demo_bir_professional.jpg',
        'permit_file' => 'demo_permit_professional.jpg',
        'barangay' => 'Poblacion',
        'branch_name' => 'Dasmariñas Main',
    ],
];

echo "\n  Seeding into ", $label, "\n\n";

foreach ($businesses as $b) {

    /* Already there? Leave it. */
    $check = $conn->prepare("SELECT company_id FROM company WHERE email = ? LIMIT 1");
    $check->bind_param("s", $b['email']);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();
    $check->close();

    if ($existing) {
        printf("  %-32s already present (company %d)\n", $b['name'], $existing['company_id']);
        continue;
    }

    $planStmt = $conn->prepare("SELECT plan_id, monthly_price FROM subscription_plans WHERE plan_name = ? LIMIT 1");
    $planStmt->bind_param("s", $b['plan']);
    $planStmt->execute();
    $plan = $planStmt->get_result()->fetch_assoc();
    $planStmt->close();

    if (!$plan) {
        printf("  %-32s SKIPPED: no plan named %s\n", $b['name'], $b['plan']);
        continue;
    }

    $ownerName = trim($b['owner_first'] . ' ' . $b['owner_middle'] . ' ' . $b['owner_last']);

    /*
    | Active, not Pending. These exist to be signed into, and a demonstration
    | business that first has to be approved demonstrates the approval queue
    | rather than the system.
    */
    $stmt = $conn->prepare("
        INSERT INTO company
            (company_code, company_name, business_type, owner_name,
             owner_last_name, owner_first_name, owner_middle_name, email, phone,
             address, city, province, postal_code,
             number_of_branches, estimated_employees, business_asset_range, business_size,
             dti_sec_registration, dti_registration_date, dti_expiry_date, dti_sec_document,
             tin_number, bir_registration_date, bir_rdo_code, bir_ocn, tin_document,
             business_permit_document, supporting_document,
             status, submitted_at, reviewed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW(), NOW())
    ");

    $stmt->bind_param(
        "sssssssssssssiisssssssssssss",
        $b['code'], $b['name'], $b['type'], $ownerName,
        $b['owner_last'], $b['owner_first'], $b['owner_middle'], $b['email'], $b['phone'],
        $b['address'], $b['city'], $b['province'], $b['postal'],
        $b['branches'], $b['employees'], $b['assets'], $b['size'],
        $b['dti_number'], $b['dti_date'], $b['dti_expiry'], $b['dti_file'],
        $b['tin'], $b['bir_date'], $b['bir_rdo'], $b['bir_ocn'], $b['bir_file'],
        $b['permit_file'], $b['permit_file']
    );

    if (!$stmt->execute()) {
        printf("  %-32s FAILED: %s\n", $b['name'], $stmt->error);
        $stmt->close();
        continue;
    }

    $companyId = (int) $conn->insert_id;
    $stmt->close();

    /* The subscription, paid and running for a year. */
    $sub = $conn->prepare("
        INSERT INTO company_subscriptions
            (company_id, plan_id, billing_cycle, amount, start_date, expiry_date,
             payment_status, status, notes)
        VALUES (?, ?, 'Monthly', ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR),
                'Paid', 'Active', 'Seeded for demonstration.')
    ");
    $sub->bind_param("iid", $companyId, $plan['plan_id'], $plan['monthly_price']);

    if (!$sub->execute()) {
        printf("  %-32s subscription FAILED: %s\n", $b['name'], $sub->error);
    }

    $sub->close();

    /* The owner's account, active, with a password that is written down. */
    $hash = password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT);

    $user = $conn->prepare("
        INSERT INTO users
            (company_id, username, fullname, last_name, first_name, middle_name,
             email, contact, password, role, status, email_verified_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'admin', 'active', NOW())
    ");
    $user->bind_param(
        "issssssss",
        $companyId, $b['email'], $ownerName,
        $b['owner_last'], $b['owner_first'], $b['owner_middle'],
        $b['email'], $b['phone'], $hash
    );
    if (!$user->execute()) {
        printf("  %-32s owner account FAILED: %s\n", $b['name'], $user->error);
        $user->close();
        continue;
    }

    $userId = (int) $conn->insert_id;
    $user->close();

    /* One branch, because employees and job postings are tied to one and a
       company with none cannot hire. */
    /*
    | barangay and opening_date are NOT NULL with no default. MariaDB fills
    | them with an empty string and a zero date without comment; MySQL 8.4
    | refuses the row -- which is how the first run created two companies
    | with no branch at all, and said so only because every statement here is
    | checked.
    */
    $branch = $conn->prepare("
        INSERT INTO branch
            (company_id, branch_name, complete_address, barangay, province, city,
             opening_date, status)
        VALUES (?, ?, ?, ?, ?, ?, CURDATE(), 'Active')
    ");
    $branch->bind_param("isssss", $companyId, $b['branch_name'], $b['address'],
        $b['barangay'], $b['province'], $b['city']);

    if (!$branch->execute()) {
        printf("  %-32s branch FAILED: %s\n", $b['name'], $branch->error);
    }

    $branch->close();

    /* Why it is Active without having been queued, written where somebody
       reading the review history will find it. */
    @$conn->query("
        INSERT INTO company_review_history (company_id, action, reason, created_at)
        VALUES ({$companyId}, 'Approved', 'Seeded for demonstration; not a real submission.', NOW())
    ");

    printf("  %-32s company %-6d user %-8d %s\n",
        $b['name'], $companyId, $userId, $b['plan']);
}

echo "\n  Sign in with:\n";

foreach ($businesses as $b) {
    printf("    %-42s %s\n", $b['email'], DEMO_PASSWORD);
}

echo "\n";
