<?php

/*
|--------------------------------------------------------------------------
| RetailCore Employment Contract — Printable / PDF View
|--------------------------------------------------------------------------
| Combined single file (PHP + CSS + JS).
| Pulls live employee, employment, job, branch, and contract data
| from the database instead of using placeholder/prototype values.
|--------------------------------------------------------------------------
*/

require_once('../init.php');
requireRole(['hr', 'admin']);

/*
| The owner reaches this too -- employee_registration.php, which they
| already have, links straight here. It prints a contract and includes
| no header or footer, so there is no chrome to choose.
*/

$companyId = requireCompany();

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$employeeId = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : 0;

if ($employeeId <= 0) {
    die('Invalid employee.');
}

/* =========================================================
   LOAD EMPLOYEE + JOB + BRANCH + EMPLOYMENT + CONTRACT
========================================================= */

$stmt = mysqli_prepare($conn, "
    SELECT
        e.employee_id,
        e.employee_code,
        e.first_name,
        e.middle_name,
        e.last_name,
        e.suffix,
        e.email,
        e.phone,
        e.location,
        e.date_of_birth,

        j.job_title,
        j.department,

        b.branch_name,
        b.complete_address,
        b.city,
        b.province,

        em.employment_type,
        em.job_level,
        em.supervisor_id,
        em.salary,
        em.salary_type,
        em.pay_frequency,
        em.hire_date,
        em.official_start_date,

        c.contract_number,
        c.contract_title,
        c.status AS contract_status,
        c.uploaded_at AS contract_issued_at,

        su.fullname AS supervisor_name

    FROM employees e

    LEFT JOIN job j
        ON e.job_id = j.job_id

    LEFT JOIN branch b
        ON e.branch_id = b.branch_id

    LEFT JOIN employment em
        ON em.employee_id = e.employee_id

    LEFT JOIN employee_contracts c
        ON c.employee_id = e.employee_id

    LEFT JOIN users su
        ON su.user_id = em.supervisor_id

    WHERE e.employee_id = ? AND e.company_id = ?
    LIMIT 1
");

mysqli_stmt_bind_param($stmt, "ii", $employeeId, $companyId);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

if (!$row) {
    die('Employee record not found.');
}

/* =========================================================
   HELPERS
========================================================= */

function contractFormatDate($value, $fallback = '__________________________')
{
    if (empty($value) || $value === '0000-00-00') {
        return $fallback;
    }

    $timestamp = strtotime($value);

    return $timestamp ? date('F j, Y', $timestamp) : $fallback;
}

function contractAddMonths($value, $months)
{
    if (empty($value) || $value === '0000-00-00') {
        return null;
    }

    $timestamp = strtotime($value);

    if (!$timestamp) {
        return null;
    }

    return date('Y-m-d', strtotime("+{$months} months", $timestamp));
}

/* =========================================================
   BUILD CONTRACT DATA
========================================================= */

$fullName = trim(
    $row['first_name'] . ' ' .
    ($row['middle_name'] ?? '') . ' ' .
    $row['last_name'] . ' ' .
    ($row['suffix'] ?? '')
);

$fullName = preg_replace('/\s+/', ' ', $fullName);

$contractStartRaw = $row['official_start_date'] ?: $row['hire_date'];
$contractEndRaw = contractAddMonths($contractStartRaw, 6);

/* Fall back to a draft contract number/title if the employee
   has not been fully registered yet (employee_contracts row
   is only created once registration Step 7 is completed). */

$contractNumber = $row['contract_number']
    ?: ('DRAFT-' . str_pad((string) $employeeId, 5, '0', STR_PAD_LEFT));

$contractTitle = $row['contract_title']
    ?: ('Employment Contract - ' . $fullName);

$employee = [

    "contract_no" => $contractNumber,
    "date_issued" => contractFormatDate($row['contract_issued_at'] ?: date('Y-m-d')),

    "employee_id" => $row['employee_code'] ?: ('EMP-' . str_pad((string) $employeeId, 4, '0', STR_PAD_LEFT)),
    "full_name" => $fullName !== '' ? $fullName : '-',
    "address" => $row['location'] ?: '__________________________',
    "contact" => $row['phone'] ?: '-',
    "email" => $row['email'] ?: '-',
    "birthdate" => contractFormatDate($row['date_of_birth']),

    "position" => $row['job_title'] ?: '-',
    "department" => $row['department'] ?: '-',
    "branch" => $row['branch_name'] ?: '-',
    "branch_address" => $row['complete_address'] ?: trim(($row['city'] ?? '') . ', ' . ($row['province'] ?? ''), ', '),

    "employment_status" => $row['employment_type'] ?: 'Probationary',
    "date_of_hire" => contractFormatDate($row['hire_date'] ?: $contractStartRaw),

    "supervisor" => $row['supervisor_name'] ?: 'Branch Supervisor',

    "salary_rate" => $row['salary'] !== null ? ('₱' . number_format((float) $row['salary'], 2)) : '__________',
    "salary_type" => $row['salary_type'] ?: '-',
    "pay_frequency" => $row['pay_frequency'] ?: '-',

    "contract_start" => contractFormatDate($contractStartRaw),
    "contract_end" => contractFormatDate($contractEndRaw)

];

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        RetailCore Employment Contract -
        <?= htmlspecialchars($employee['full_name']); ?>
    </title>

    <style>
        /* =====================================================
   GLOBAL
===================================================== */

        * {
            box-sizing: border-box;
        }

        body {

            margin: 0;

            background: #e9edf3;

            font-family: "Poppins", Arial, sans-serif;

            color: #172033;

        }


        /* =====================================================
   ACTION BAR
===================================================== */

        .action-bar {

            position: sticky;

            top: 0;

            z-index: 100;

            padding: 15px;

            background: #00224c;

            display: flex;

            justify-content: center;

            gap: 10px;

        }

        .action-bar button {

            border: none;

            padding: 11px 20px;

            border-radius: 7px;

            font-weight: 600;

            cursor: pointer;

        }

        .print-btn {

            background: #fbbd23;

            color: #00224c;

        }

        .back-btn {

            background: white;

            color: #00224c;

        }


        /* =====================================================
   CONTRACT PAGE
===================================================== */

        .contract-page {

            position: relative;

            width: 210mm;

            min-height: 297mm;

            margin: 30px auto;

            background: white;

            padding: 22mm 18mm 20mm 18mm;

            box-shadow: 0 8px 30px rgba(0, 0, 0, .12);

            page-break-after: always;

            overflow: hidden;

        }


        /* =====================================================
   TOP BORDER
===================================================== */

        .top-border {

            position: absolute;

            top: 0;

            left: 0;

            width: 100%;

            height: 9px;

            background: #00224c;

        }

        .top-border::after {

            content: "";

            position: absolute;

            right: 0;

            top: 0;

            width: 28%;

            height: 9px;

            background: #fbbd23;

        }


        /* =====================================================
   HEADER
===================================================== */

        .contract-header {

            display: flex;

            justify-content: space-between;

            align-items: flex-start;

            margin-bottom: 20px;

        }

        .logo-area img {

            width: 180px;

            height: auto;

        }

        .contract-meta {

            font-size: 10px;

            line-height: 1.6;

            text-align: right;

        }

        .contract-meta p {

            margin: 4px 0;

        }


        /* =====================================================
   TITLE
===================================================== */

        h1 {

            text-align: center;

            color: #00224c;

            font-size: 26px;

            margin: 10px 0 20px;

            letter-spacing: .5px;

        }


        /* =====================================================
   INTRODUCTION
===================================================== */

        .intro {

            font-size: 10.5px;

            line-height: 1.65;

            text-align: justify;

        }

        .center-text {

            text-align: center;

        }


        /* =====================================================
   SECTION TITLE
===================================================== */

        .section-title {

            margin-top: 20px;

            margin-bottom: 8px;

            padding: 8px 10px;

            background: #00224c;

            color: #fbbd23;

            font-size: 11px;

            font-weight: 700;

        }


        /* =====================================================
   EMPLOYEE TABLE
===================================================== */

        .employee-table {

            width: 100%;

            border-collapse: collapse;

            font-size: 9px;

        }

        .employee-table td {

            width: 50%;

            border: 1px solid #aeb8c5;

            padding: 8px;

            vertical-align: top;

        }

        .employee-table strong {

            font-size: 8px;

            color: #00224c;

        }


        /* =====================================================
   CONTRACT SECTIONS
===================================================== */

        .contract-section {

            margin-top: 17px;

        }

        .contract-section h2 {

            color: #00224c;

            font-size: 13px;

            margin-bottom: 7px;

            border-left: 4px solid #fbbd23;

            padding-left: 8px;

        }

        .contract-section p {

            font-size: 10.5px;

            line-height: 1.65;

            text-align: justify;

        }

        .contract-section li {

            font-size: 10px;

            line-height: 1.7;

            margin-bottom: 3px;

        }


        /* =====================================================
   SALARY TABLE
===================================================== */

        .salary-table {

            width: 100%;

            border-collapse: collapse;

            margin: 12px 0;

            font-size: 10px;

        }

        .salary-table td {

            border: 1px solid #b8c0ca;

            padding: 9px;

        }

        .salary-table td:first-child {

            background: #f1f4f8;

            width: 40%;

            color: #00224c;

        }


        /* =====================================================
   CONTRACT PERIOD
===================================================== */

        .contract-period {

            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 15px;

            margin-top: 12px;

        }

        .contract-period div {

            border: 1px solid #ccd3dc;

            padding: 12px;

            text-align: center;

            font-size: 10px;

        }


        /* =====================================================
   VALIDITY
===================================================== */

        .validity-box {

            display: flex;

            align-items: center;

            justify-content: center;

            gap: 25px;

            padding: 14px;

            background: #f6f8fb;

            border: 1px solid #ccd3dc;

            margin: 12px 0;

            color: #00224c;

        }


        /* =====================================================
   ACKNOWLEDGEMENT
===================================================== */

        .acknowledgement {

            margin-top: 20px;

            border-top: 2px solid #00224c;

            padding-top: 12px;

        }

        .acknowledgement h2 {

            text-align: center;

            color: #00224c;

            font-size: 14px;

        }

        .acknowledgement p {

            font-size: 10px;

            line-height: 1.65;

            text-align: justify;

        }


        /* =====================================================
   SIGNATURE
===================================================== */

        .signature-title {

            text-align: center;

            font-weight: 700;

            font-size: 11px;

            color: #00224c;

            margin: 25px 0 15px;

        }

        .signature-grid {

            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 15px;

        }

        .signature-box {

            border: 1px solid #aeb8c5;

            padding: 12px;

            min-height: 150px;

        }

        .signature-box h3 {

            color: #00224c;

            font-size: 10px;

            margin-top: 0;

        }

        .signature-box p {

            font-size: 9px;

            margin: 7px 0;

        }

        .signature-line {

            width: 85%;

            height: 25px;

            border-bottom: 1px solid #172033;

            margin: auto;

        }


        /* =====================================================
   WITNESS
===================================================== */

        .witness-heading {

            text-align: center;

            color: #00224c;

            font-size: 11px;

            margin-top: 20px;

        }

        .witness-grid {

            display: grid;

            grid-template-columns: 1fr 1fr;

            gap: 35px;

            font-size: 9px;

        }

        .witness-grid p {

            margin: 5px 0;

        }


        /* =====================================================
   NOTARIAL
===================================================== */

        .notarial {

            margin-top: 20px;

            font-size: 9px;

            line-height: 1.6;

        }

        .notarial h2 {

            text-align: center;

            color: #00224c;

            font-size: 13px;

        }

        .notary-signature {

            text-align: right;

            margin-right: 40px;

        }


        /* =====================================================
   FOOTER
===================================================== */

        .page-footer {

            position: absolute;

            bottom: 0;

            left: 0;

            width: 100%;

            min-height: 35px;

            background: #00224c;

            color: white;

            padding: 8px 18mm;

            display: flex;

            justify-content: space-between;

            align-items: center;

            font-size: 8px;

        }

        .page-footer span:last-child {

            color: #fbbd23;

            font-weight: bold;

        }


        /* =====================================================
   PRINT
===================================================== */

        @media print {

            @page {

                size: A4;

                margin: 0;

            }

            body {

                background: white;

            }

            .action-bar {

                display: none;

            }

            .contract-page {

                width: 210mm;

                height: 297mm;

                min-height: 297mm;

                margin: 0;

                box-shadow: none;

                page-break-after: always;

            }

        }


        /* =====================================================
   RESPONSIVE
===================================================== */

        @media (max-width: 900px) {

            .contract-page {

                width: 95%;

                min-height: auto;

                padding: 40px 30px 70px;

            }

            .signature-grid,
            .witness-grid,
            .contract-period {

                grid-template-columns: 1fr;

            }

            .page-footer {

                position: relative;

                margin-top: 30px;

                margin-left: -30px;

                width: calc(100% + 60px);

            }

        }
    </style>

</head>

<body>

    <!-- =====================================================
     ACTION BAR
====================================================== -->

    <div class="action-bar">

        <button onclick="window.print()" class="print-btn">

            🖨 Print / Save PDF

        </button>

        <button onclick="window.close()" class="back-btn">

            ← Back

        </button>

    </div>


    <!-- =====================================================
     CONTRACT DOCUMENT
====================================================== -->

    <div class="contract-document">


        <!-- =====================================================
     PAGE 1
====================================================== -->

        <section class="contract-page">

            <div class="top-border"></div>


            <!-- HEADER -->

            <header class="contract-header">

                <div class="logo-area">

                    <img src="../assets/retailcore-logo.png" alt="RetailCore Logo">

                </div>

                <div class="contract-meta">

                    <p>
                        <strong>CONTRACT NO.:</strong>
                        <?= htmlspecialchars($employee["contract_no"]); ?>
                    </p>

                    <p>
                        <strong>DATE ISSUED:</strong>
                        <?= htmlspecialchars($employee["date_issued"]); ?>
                    </p>

                </div>

            </header>


            <h1>
                EMPLOYMENT CONTRACT
            </h1>


            <!-- INTRODUCTION -->

            <div class="intro">

                <p>

                    This Employment Contract ("Contract") is entered into
                    by and between:

                </p>

                <p>

                    <strong>RETAILCORE RETAIL, INC.</strong>,
                    a corporation duly organized and existing under
                    the laws of the Republic of the Philippines,
                    with principal office at __________________________,
                    hereinafter referred to as the <strong>"COMPANY"</strong>;

                </p>

                <p class="center-text">

                    - and -

                </p>

                <p>

                    <strong>
                        <?= htmlspecialchars($employee["full_name"]); ?>
                    </strong>,

                    hereinafter referred to as the
                    <strong>"EMPLOYEE".</strong>

                </p>

                <p>

                    The Company and the Employee may individually be
                    referred to as a "Party" and collectively as the
                    "Parties."

                </p>

            </div>


            <!-- EMPLOYEE INFORMATION -->

            <div class="section-title">

                EMPLOYEE INFORMATION

            </div>


            <table class="employee-table">

                <tr>

                    <td>
                        <strong>FULL NAME</strong><br>
                        <?= htmlspecialchars($employee["full_name"]); ?>
                    </td>

                    <td>
                        <strong>EMPLOYEE ID NO.</strong><br>
                        <?= htmlspecialchars($employee["employee_id"]); ?>
                    </td>

                </tr>

                <tr>

                    <td>
                        <strong>ADDRESS</strong><br>
                        <?= htmlspecialchars($employee["address"]); ?>
                    </td>

                    <td>
                        <strong>CONTACT NUMBER</strong><br>
                        <?= htmlspecialchars($employee["contact"]); ?>
                    </td>

                </tr>

                <tr>

                    <td>
                        <strong>EMAIL ADDRESS</strong><br>
                        <?= htmlspecialchars($employee["email"]); ?>
                    </td>

                    <td>
                        <strong>DATE OF BIRTH</strong><br>
                        <?= htmlspecialchars($employee["birthdate"]); ?>
                    </td>

                </tr>

                <tr>

                    <td>
                        <strong>POSITION / JOB TITLE</strong><br>
                        <?= htmlspecialchars($employee["position"]); ?>
                    </td>

                    <td>
                        <strong>DEPARTMENT</strong><br>
                        <?= htmlspecialchars($employee["department"]); ?>
                    </td>

                </tr>

                <tr>

                    <td>
                        <strong>ASSIGNED BRANCH</strong><br>
                        <?= htmlspecialchars($employee["branch"]); ?>
                    </td>

                    <td>
                        <strong>BRANCH ADDRESS</strong><br>
                        <?= htmlspecialchars($employee["branch_address"]); ?>
                    </td>

                </tr>

                <tr>

                    <td>

                        <strong>EMPLOYMENT STATUS</strong><br>

                        <?= htmlspecialchars($employee["employment_status"]); ?>

                    </td>

                    <td>

                        <strong>DATE OF HIRE</strong><br>

                        <?= htmlspecialchars($employee["date_of_hire"]); ?>

                    </td>

                </tr>

            </table>


            <!-- OBJECTIVE -->

            <div class="contract-section">

                <h2>
                    1. OBJECTIVE OF EMPLOYMENT
                </h2>

                <p>

                    The Employee is engaged to contribute to the efficient
                    and orderly operation of RetailCore's retail and
                    sari-sari store business.

                </p>

                <p>

                    The Employee shall perform assigned duties with
                    professionalism, accuracy, integrity, and respect
                    for customers, co-workers, company property,
                    inventory, and business procedures.

                </p>

            </div>


            <!-- DUTIES -->

            <div class="contract-section">

                <h2>
                    2. DUTIES AND RESPONSIBILITIES
                </h2>

                <p>
                    The Employee shall faithfully perform the duties
                    assigned to the position, including but not limited to:
                </p>

                <ul>

                    <li>
                        Provide excellent customer service.
                    </li>

                    <li>
                        Properly handle sales transactions.
                    </li>

                    <li>
                        Maintain accuracy in cash and transaction records.
                    </li>

                    <li>
                        Assist in monitoring store inventory.
                    </li>

                    <li>
                        Maintain cleanliness and organization of the store.
                    </li>

                    <li>
                        Follow company policies and operational procedures.
                    </li>

                    <li>
                        Perform other reasonable duties assigned by the
                        immediate supervisor.
                    </li>

                </ul>

            </div>


            <div class="page-footer">

                <span>
                    RetailCore Retail, Inc.
                </span>

                <span>
                    Your Smart Partner for Every Sale.
                </span>

                <span>
                    PAGE 1 OF 3
                </span>

            </div>

        </section>


        <!-- =====================================================
     PAGE 2
====================================================== -->

        <section class="contract-page">

            <div class="top-border"></div>


            <div class="contract-section">

                <h2>
                    3. WORK SCHEDULE
                </h2>

                <p>

                    The Employee shall observe the work schedule assigned
                    by the Company and shall comply with the applicable
                    attendance and timekeeping procedures.

                </p>

                <p>

                    Work schedules may include weekdays, weekends,
                    holidays, or rest days depending on store operations
                    and business requirements.

                </p>

            </div>


            <!-- COMPENSATION -->

            <div class="contract-section">

                <h2>
                    4. COMPENSATION AND BENEFITS
                </h2>

                <p>

                    In consideration of the services rendered,
                    the Employee shall receive compensation based
                    on the employment terms approved by the Company.

                </p>


                <table class="salary-table">

                    <tr>

                        <td>
                            <strong>Salary Rate</strong>
                        </td>

                        <td>
                            <?= htmlspecialchars($employee["salary_rate"]); ?>
                        </td>

                    </tr>

                    <tr>

                        <td>
                            <strong>Salary Type</strong>
                        </td>

                        <td>
                            <?= htmlspecialchars($employee["salary_type"]); ?>
                        </td>

                    </tr>

                    <tr>

                        <td>
                            <strong>Pay Frequency</strong>
                        </td>

                        <td>
                            <?= htmlspecialchars($employee["pay_frequency"]); ?>
                        </td>

                    </tr>

                </table>


                <p>

                    The Employee shall receive applicable statutory
                    benefits and deductions in accordance with applicable
                    Philippine laws and company policies.

                </p>

                <p>

                    Applicable statutory contributions may include
                    SSS, PhilHealth, and Pag-IBIG, as applicable.

                </p>

            </div>


            <!-- PROBATIONARY -->

            <div class="contract-section">

                <h2>
                    5. EMPLOYMENT PERIOD
                </h2>

                <p>

                    The Employee's employment shall commence on
                    <strong>
                        <?= htmlspecialchars($employee["contract_start"]); ?>
                    </strong>

                    and shall remain effective according to the
                    employment status and applicable terms stated
                    in this Contract.

                </p>

                <div class="contract-period">

                    <div>

                        <strong>
                            CONTRACT START
                        </strong>

                        <br>

                        <?= htmlspecialchars($employee["contract_start"]); ?>

                    </div>

                    <div>

                        <strong>
                            PROBATIONARY EVALUATION
                        </strong>

                        <br>

                        <?= htmlspecialchars($employee["contract_end"]); ?>

                    </div>

                </div>

            </div>


            <!-- COMPANY POLICIES -->

            <div class="contract-section">

                <h2>
                    6. COMPANY POLICIES AND COMPLIANCE
                </h2>

                <p>

                    The Employee agrees to comply with the Company's
                    policies, procedures, standards, and reasonable
                    instructions, including but not limited to:

                </p>

                <ul>

                    <li>
                        Attendance and Punctuality Policy
                    </li>

                    <li>
                        Cash Handling Policy
                    </li>

                    <li>
                        Inventory Handling Policy
                    </li>

                    <li>
                        Customer Service Policy
                    </li>

                    <li>
                        Workplace Safety and Sanitation Policy
                    </li>

                    <li>
                        Data Privacy and Confidentiality Policy
                    </li>

                    <li>
                        Code of Conduct
                    </li>

                </ul>

            </div>


            <!-- CONFIDENTIALITY -->

            <div class="contract-section">

                <h2>
                    7. CONFIDENTIALITY
                </h2>

                <p>

                    The Employee shall maintain confidentiality of
                    non-public company information, including customer
                    information, employee records, sales information,
                    inventory records, pricing information, business
                    plans, and system credentials.

                </p>

            </div>


            <!-- COMPANY PROPERTY -->

            <div class="contract-section">

                <h2>
                    8. COMPANY PROPERTY
                </h2>

                <p>

                    The Employee shall properly handle and safeguard
                    company property, equipment, documents, cash,
                    inventory, and system access credentials entrusted
                    to them.

                </p>

            </div>


            <div class="page-footer">

                <span>
                    RetailCore Retail, Inc.
                </span>

                <span>
                    Your Smart Partner for Every Sale.
                </span>

                <span>
                    PAGE 2 OF 3
                </span>

            </div>

        </section>


        <!-- =====================================================
     PAGE 3
====================================================== -->

        <section class="contract-page">

            <div class="top-border"></div>


            <!-- TERMINATION -->

            <div class="contract-section">

                <h2>
                    9. TERMINATION OF EMPLOYMENT
                </h2>

                <p>

                    This Contract may be terminated in accordance with
                    applicable laws, company policies, and the terms
                    governing the Employee's employment.

                </p>

                <p>

                    Grounds for disciplinary action or employment
                    separation may include serious violations of
                    company policies, misconduct, dishonesty,
                    repeated violations of attendance requirements,
                    or other grounds recognized under applicable laws.

                </p>

            </div>


            <!-- CONTRACT VALIDITY -->

            <div class="contract-section">

                <h2>
                    10. LENGTH AND VALIDITY OF CONTRACT
                </h2>

                <p>

                    This Contract shall take effect on:

                </p>

                <div class="validity-box">

                    <strong>
                        <?= htmlspecialchars($employee["contract_start"]); ?>
                    </strong>

                    <span>
                        TO
                    </span>

                    <strong>
                        <?= htmlspecialchars($employee["contract_end"]); ?>
                    </strong>

                </div>

                <p>

                    Any renewal, modification, or change in employment
                    terms shall be subject to Company approval and
                    applicable employment requirements.

                </p>

            </div>


            <!-- GOVERNING LAW -->

            <div class="contract-section">

                <h2>
                    11. GOVERNING LAW
                </h2>

                <p>

                    This Contract shall be governed by and construed
                    in accordance with the applicable laws of the
                    Republic of the Philippines.

                </p>

            </div>


            <!-- ACKNOWLEDGEMENT -->

            <div class="acknowledgement">

                <h2>
                    ACKNOWLEDGEMENT
                </h2>

                <p>

                    I hereby acknowledge that I have read and understood
                    the terms and conditions stated in this Employment
                    Contract. I understand my duties, responsibilities,
                    employment terms, and obligations as an employee
                    of RetailCore.

                </p>

                <p>

                    I voluntarily accept the terms stated herein and
                    agree to comply with applicable company policies
                    and employment requirements.

                </p>

            </div>


            <!-- SIGNATURES -->

            <div class="signature-title">

                IN WITNESS WHEREOF

            </div>


            <div class="signature-grid">


                <div class="signature-box">

                    <h3>
                        FOR THE COMPANY
                    </h3>

                    <div class="signature-line"></div>

                    <p>
                        Authorized Representative
                    </p>

                    <p>
                        Name:
                        __________________________
                    </p>

                    <p>
                        Position:
                        __________________________
                    </p>

                    <p>
                        Date Signed:
                        __________________________
                    </p>

                </div>


                <div class="signature-box">

                    <h3>
                        EMPLOYEE
                    </h3>

                    <div class="signature-line"></div>

                    <p>
                        <?= htmlspecialchars($employee["full_name"]); ?>
                    </p>

                    <p>
                        Employee ID:
                        <?= htmlspecialchars($employee["employee_id"]); ?>
                    </p>

                    <p>
                        Date Signed:
                        __________________________
                    </p>

                </div>

            </div>


            <!-- WITNESSES -->

            <h3 class="witness-heading">
                WITNESSED BY
            </h3>


            <div class="witness-grid">

                <div>

                    <div class="signature-line"></div>

                    <p>
                        Witness 1 - Printed Name
                    </p>

                    <p>
                        Position: __________________
                    </p>

                    <p>
                        Date Signed: _______________
                    </p>

                </div>


                <div>

                    <div class="signature-line"></div>

                    <p>
                        Witness 2 - Printed Name
                    </p>

                    <p>
                        Position: __________________
                    </p>

                    <p>
                        Date Signed: _______________
                    </p>

                </div>

            </div>


            <!-- NOTARIAL ACKNOWLEDGEMENT -->

            <div class="notarial">

                <h2>
                    ACKNOWLEDGEMENT
                </h2>

                <p>

                    REPUBLIC OF THE PHILIPPINES )

                    <br>

                    CITY / MUNICIPALITY OF
                    ______________________ )

                </p>

                <p>

                    BEFORE ME, a Notary Public, personally appeared
                    the parties identified above and acknowledged
                    that they voluntarily executed this Employment
                    Contract and that the same is their free and
                    voluntary act and deed.

                </p>

                <br>

                <div class="notary-signature">

                    <strong>
                        NOTARY PUBLIC
                    </strong>

                    <br><br>

                    ______________________________

                    <br>

                    Name / Signature

                </div>

            </div>


            <div class="page-footer">

                <span>
                    RetailCore Retail, Inc.
                </span>

                <span>
                    Your Smart Partner for Every Sale.
                </span>

                <span>
                    PAGE 3 OF 3
                </span>

            </div>

        </section>


    </div>


    <script>
        document.addEventListener("DOMContentLoaded", function () {

            console.log("RetailCore Employment Contract Loaded.");

        });
    </script>

</body>

</html>