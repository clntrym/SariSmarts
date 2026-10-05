<?php
require_once("../init.php");
requireRole(['admin']);



$currentPage = basename($_SERVER['PHP_SELF']);

/*
| What this company's plan opens. Retail Starter sells neither multi-branch
| nor recruitment, so those two links are not drawn for it at all. The pages
| themselves call requireModule(), because hiding a link is presentation and
| the URL is still typeable.
*/
$navCompanyId = requireCompany();
$canBranch = companyHasModule($conn, $navCompanyId, 'branch');
$canHiring = companyHasModule($conn, $navCompanyId, 'hiring');

/*
| Which departments this company's plan actually has.
|
| Retail Starter sells three roles -- Owner/Admin, Cashier, Inventory Staff
| -- and the RBAC work gave every owner the HRMS and Finance dropdowns
| without asking. So a Retail Starter owner was shown Recruitment, Payroll
| and Accounts Payable: screens for staff their plan cannot create, in
| modules they did not buy.
|
| The pages themselves call requirePlanRole(), for the same reason the
| module links call requireModule(): this hides the entry, that refuses the
| address.
*/
$navPlanRoles = companyPlanRoles($conn, $navCompanyId);
$canHrms = in_array('hr', $navPlanRoles, true);
$canFinance = in_array('finance', $navPlanRoles, true);

$employeePages = [
    'approval.php',
    'stock_requests.php',
    'archive_employee.php'
];

$employeeOpen = in_array($currentPage, $employeePages);
?>

<!DOCTYPE html>

<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>HR Department</title>

    <link rel="stylesheet" href="../bootstrap-5.3.8-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../bootstrap-icons-1.13.1/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../fontawesome-free-7.0.1-web/css/all.min.css">
    <script src="../bootstrap-5.3.8-dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.dataTables.min.css">
    <script src="https://cdn.datatables.net/2.3.3/js/dataTables.min.js"></script>
    <link rel="stylesheet" href="../assets/css/datatable-theme.css">
    <script
        src="../assets/js/datatable-theme.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/datatable-theme.js') ?>"></script>

    <?php
    /*
    | branch.php is the only admin page with a map, and it was rendering as a
    | pile of unpositioned tiles because this stylesheet was never loaded --
    | only adminss_header.php, which nothing includes, ever linked it.
    | Loaded just for that page rather than on every admin screen.
    */
    ?>
    <?php if ($currentPage === 'branch.php'): ?>
        <link rel="stylesheet" href="../assets/leaflet/leaflet.css">
    <?php endif; ?>

    <style></style>

    <style>
        :root {
            --sidebar-width: 300px;
            --sidebar-collapse: 80px;
            --sidebar-bg: #00224c;
            --sidebar-hover: #fbbd23;
            --light-bg: #f5f7fb;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            overflow-x: hidden;
            background: var(--light-bg);
        }

        /* ===========================
   Sidebar
=========================== */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar-bg);
            transition: .3s ease;
            z-index: 1050;
            overflow-x: hidden;
            overflow-y: auto;
        }

        .sidebar-brand {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, .15);
            margin-left: 10px;
        }

        .sidebar-brand h5 {
            color: #fff;
            font-weight: 700;
            margin: 0;
        }

        .sidebar-brand span {
            color: #fbbd23;
            font-weight: 700;
            margin: 0;
            margin-left: 10px;
        }

        .sidebar-brand small {
            color: #d6d6d6;
            margin-left: 35px;
        }

        .menu-label {
            color: #b8c2d8;
            font-size: .75rem;
            padding: 15px 20px 5px;
            text-transform: uppercase;
        }

        .sidebar .nav-link {
            color: #fff;
            margin: 5px 10px;
            border-radius: 10px;
            padding: 12px 18px;
            transition: .3s;
            white-space: nowrap;
        }

        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: var(--sidebar-hover);
            color: black;
            font-weight: bold;
        }

        .sidebar .nav-link i {
            width: 20px;
            text-align: center;
            font-size: 18px;
        }

        .sidebar-footer {
            padding: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .sidebar-footer .sidebar-link {
            margin-bottom: 1rem;
        }

        .sidebar-copy {
            color: #ccc;
            font-size: 0.95rem;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            transition: .3s ease;
            min-height: 100vh;
        }

        .page-content {
            padding: 25px;
        }

        .top-navbar {
            height: 70px;
            background: #fff;
            border-bottom: 1px solid #ddd;
        }

        .profile-box {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .profile-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #17327D;
            color: #fff;
            display: flex;
            justify-content: center;
            align-items: center;
            font-weight: bold;
        }

        /* Collapsed Sidebar */
        .sidebar.collapsed {
            width: 80px;
        }

        .sidebar.collapsed .sidebar-brand h5 span,
        .sidebar.collapsed .sidebar-brand small,
        .sidebar.collapsed .nav-link span,
        .sidebar.collapsed .menu-label {
            display: none;
        }

        .sidebar.collapsed .nav-link {
            text-align: center;
        }

        .sidebar.collapsed .nav-link i {
            margin-right: 0;
            font-size: 1.2rem;
        }

        .main-content.expanded {
            margin-left: 80px;
        }

        .btn-primary {
            background: #00224c !important;
            border-color: #00224c !important;
            color: #fff !important;
        }

        .btn-primary:hover {
            background-color: #fbbd23 !important;
            border-color: #fbbd23 !important;
            color: #00224c !important;
        }

        /* Mobile */
        .mobile-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .45);
            opacity: 0;
            visibility: hidden;
            transition: .3s;
            z-index: 1040;
        }

        .mobile-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        @media (max-width:991.98px) {
            .sidebar {
                left: -240px;
                width: 240px;
            }

            .sidebar.show {
                left: 0;
            }

            .main-content,
            .main-content.expanded {
                margin-left: 0;
            }
        }

        /* Small Phones */
        @media(max-width:576px) {
            .page-content {
                padding: 15px;
            }

            .profile-box small {
                display: none;
            }

            .profile-box div {
                font-size: 14px;
            }
        }

        #employeeMenu .nav-link {
            padding-left: 45px;
            font-size: 14px;
            color: #d9d9d9;
        }

        #employeeMenu .nav-link:hover,
        #employeeMenu .nav-link.active {
            background: #fbbd23;
            color: #000;
            font-weight: bold;
        }

        #employeeMenu .nav-link i {
            width: 18px;
        }

        .nav-link .bi-chevron-down {
            transition: .3s;
        }

        .nav-link[aria-expanded="true"] .bi-chevron-down {
            transform: rotate(180deg);
        }
    </style>

</head>

<body>

    <div class="sidebar">
        <div class="sidebar-brand">
            <h5>
                <i class="bi bi-shop"></i>
                <span><?= htmlspecialchars(currentCompanyName()) ?></span>
            </h5>
            <small>Store POS</small>
        </div>
        <div class="mt-3">
            <small class="text-white-50 px-3">MENU</small>
            <ul class="nav flex-column mt-2">

                <?php
                /*
                | Grouped, because a flat list of nineteen links is a list
                | nobody reads. The owner reaches every module -- the business
                | is theirs -- and the groups are the four the modules
                | actually fall into.
                |
                | Each group opens when the page inside it is the current one,
                | so arriving at Payroll from anywhere leaves HRMS open rather
                | than making the owner hunt for where they are.
                |
                | The links reach into hr/, finance/ and cashier/ because that
                | is where the pages live. They keep this sidebar: each of
                | those pages now picks its chrome by who is reading it.
                */
                $hrmsPages = ['recruitment.php', 'employee_registration.php',
                              'employee_directory.php', 'approval.php',
                              'attendance.php', 'payroll.php'];

                /* tax.php is not here any more: it is its own entry below,
                   and leaving it listed would hold the Finance menu open on
                   a page that is no longer in it. */
                $financePages = ['income.php', 'expenses.php',
                                 'accounts_payable.php', 'reports.php'];

                $stockPages = ['Inventory.php', 'suppliers.php', 'stock_requests.php',
                               'receive_deliveries.php'];

                /* approval.php and reports.php exist in more than one folder,
                   so the folder decides which group is open, not the name. */
                $here = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

                $hrmsOpen = str_contains($here, '/hr/') && in_array($currentPage, $hrmsPages, true);
                $financeOpen = (str_contains($here, '/finance/') && in_array($currentPage, $financePages, true))
                    || in_array($currentPage, ['income.php', 'reports.php'], true);
                $stockOpen = in_array($currentPage, $stockPages, true)
                    || (str_contains($here, '/inventory/') && $currentPage === 'reports.php');
                ?>

                <li class="nav-item">
                    <a href="/admin/dashboard.php" class="nav-link <?= ($currentPage == 'dashboard.php') ? 'active' : '' ?>">
                        <i class="bi bi-grid-1x2 me-2"></i>
                        <span>Dashboard</span>
                    </a>
                </li>

                <!-- ============================ HRMS ============================ -->
                <?php if ($canHrms): ?>
                <li class="nav-item">
                    <a class="nav-link d-flex justify-content-between align-items-center <?= $hrmsOpen ? '' : 'collapsed' ?>"
                        data-bs-toggle="collapse" href="#hrmsMenu" role="button"
                        aria-expanded="<?= $hrmsOpen ? 'true' : 'false' ?>">
                        <span>
                            <i class="bi bi-people me-2"></i>
                            <span>HRMS</span>
                        </span>
                        <i class="bi bi-chevron-down"></i>
                    </a>

                    <div class="collapse <?= $hrmsOpen ? 'show' : '' ?>" id="hrmsMenu">
                        <ul class="nav flex-column ms-3" id="employeeMenu">
                            <li class="nav-item">
                                <a href="/hr/recruitment.php" class="nav-link <?= ($hrmsOpen && $currentPage == 'recruitment.php') ? 'active' : '' ?>">
                                    <i class="bi bi-megaphone me-2"></i><span>Recruitment</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="/hr/employee_registration.php" class="nav-link <?= ($hrmsOpen && $currentPage == 'employee_registration.php') ? 'active' : '' ?>">
                                    <i class="bi bi-person-plus me-2"></i><span>Employee Registration</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="/hr/employee_directory.php" class="nav-link <?= ($hrmsOpen && $currentPage == 'employee_directory.php') ? 'active' : '' ?>">
                                    <i class="bi bi-person-vcard me-2"></i><span>Employee Management</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="/hr/approval.php" class="nav-link <?= ($hrmsOpen && $currentPage == 'approval.php') ? 'active' : '' ?>">
                                    <i class="bi bi-calendar-check me-2"></i><span>Leave Requests</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="/hr/attendance.php" class="nav-link <?= ($hrmsOpen && $currentPage == 'attendance.php') ? 'active' : '' ?>">
                                    <i class="bi bi-clipboard2-check me-2"></i><span>Time &amp; Undertime</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="/hr/payroll.php" class="nav-link <?= ($hrmsOpen && $currentPage == 'payroll.php') ? 'active' : '' ?>">
                                    <i class="bi bi-cash-stack me-2"></i><span>Payroll</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <?php endif; ?>

                <!-- =========================== FINANCE =========================== -->
                <?php if ($canFinance): ?>
                <li class="nav-item">
                    <a class="nav-link d-flex justify-content-between align-items-center <?= $financeOpen ? '' : 'collapsed' ?>"
                        data-bs-toggle="collapse" href="#financeMenu" role="button"
                        aria-expanded="<?= $financeOpen ? 'true' : 'false' ?>">
                        <span>
                            <i class="bi bi-wallet2 me-2"></i>
                            <span>Finance</span>
                        </span>
                        <i class="bi bi-chevron-down"></i>
                    </a>

                    <div class="collapse <?= $financeOpen ? 'show' : '' ?>" id="financeMenu">
                        <ul class="nav flex-column ms-3" id="employeeMenu">
                            <li class="nav-item">
                                <a href="/admin/income.php" class="nav-link <?= ($currentPage == 'income.php') ? 'active' : '' ?>">
                                    <i class="bi bi-graph-up-arrow me-2"></i><span>Income</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="/finance/expenses.php" class="nav-link <?= ($currentPage == 'expenses.php') ? 'active' : '' ?>">
                                    <i class="bi bi-receipt-cutoff me-2"></i><span>Expenses</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="/finance/accounts_payable.php" class="nav-link <?= ($currentPage == 'accounts_payable.php') ? 'active' : '' ?>">
                                    <i class="bi bi-journal-text me-2"></i><span>Accounts Payable</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <?php endif; ?>

                <!-- ========================== INVENTORY ========================== -->
                <li class="nav-item">
                    <a class="nav-link d-flex justify-content-between align-items-center <?= $stockOpen ? '' : 'collapsed' ?>"
                        data-bs-toggle="collapse" href="#stockMenu" role="button"
                        aria-expanded="<?= $stockOpen ? 'true' : 'false' ?>">
                        <span>
                            <i class="bi bi-box-seam me-2"></i>
                            <span>Inventory</span>
                        </span>
                        <i class="bi bi-chevron-down"></i>
                    </a>

                    <div class="collapse <?= $stockOpen ? 'show' : '' ?>" id="stockMenu">
                        <ul class="nav flex-column ms-3" id="employeeMenu">
                            <li class="nav-item">
                                <a href="/admin/Inventory.php" class="nav-link <?= ($currentPage == 'Inventory.php') ? 'active' : '' ?>">
                                    <i class="bi bi-boxes me-2"></i><span>Products</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="/admin/suppliers.php" class="nav-link <?= ($currentPage == 'suppliers.php') ? 'active' : '' ?>">
                                    <i class="bi bi-truck me-2"></i><span>Suppliers</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="/admin/stock_requests.php" class="nav-link <?= ($currentPage == 'stock_requests.php') ? 'active' : '' ?>">
                                    <i class="bi bi-clipboard-plus me-2"></i><span>Stock Requests</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="/admin/receive_deliveries.php" class="nav-link <?= ($currentPage == 'receive_deliveries.php') ? 'active' : '' ?>">
                                    <i class="bi bi-box-arrow-in-down me-2"></i><span>Receive Deliveries</span>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a href="/inventory/reports.php" class="nav-link <?= (str_contains($here, '/inventory/') && $currentPage == 'reports.php') ? 'active' : '' ?>">
                                    <i class="bi bi-bar-chart-line me-2"></i><span>Inventory Reports</span>
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>

                <!-- ============================ CASHIER =========================== -->
                <li class="nav-item">
                    <a href="/cashier/pointofsales.php" class="nav-link <?= ($currentPage == 'pointofsales.php') ? 'active' : '' ?>">
                        <i class="bi bi-cart3 me-2"></i>
                        <span>Point of Sale</span>
                    </a>
                </li>

                <!-- ====================== RUNNING THE BUSINESS ==================== -->
                <li class="nav-item mt-2"><small class="text-white-50 px-3">ADMINISTRATION</small></li>

                <!--
                    Tax sits here rather than in the Finance menu.

                    It used to be an item in that dropdown, so gating the
                    dropdown took it away from Retail Starter -- and a
                    sari-sari store still files with the BIR. The obligation
                    comes from being a business, not from having bought a
                    Finance seat, so every owner has it on every plan.
                -->
                <li class="nav-item">
                    <a href="/admin/tax.php" class="nav-link <?= ($currentPage == 'tax.php') ? 'active' : '' ?>">
                        <i class="bi bi-percent me-2"></i>
                        <span>Tax</span>
                    </a>
                </li>

                <?php if ($canHiring): ?>
                    <li class="nav-item">
                        <a href="/admin/approval.php" class="nav-link <?= (str_contains($here, '/admin/') && $currentPage == 'approval.php') ? 'active' : '' ?>">
                            <i class="bi bi-person-check me-2"></i>
                            <span>Hiring Approval</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if ($canBranch): ?>
                    <li class="nav-item">
                        <a href="/admin/branch.php" class="nav-link <?= ($currentPage == 'branch.php') ? 'active' : '' ?>">
                            <i class="bi bi-shop me-2"></i>
                            <span>Branch</span>
                        </a>
                    </li>
                <?php endif; ?>

                <li class="nav-item">
                    <a href="/admin/user_management.php" class="nav-link <?= ($currentPage == 'user_management.php') ? 'active' : '' ?>">
                        <i class="bi bi-people-fill me-2"></i>
                        <span>User Management</span>
                    </a>
                </li>
            </ul>
                    </div>
                </li>
                <li class="nav-item">
                    <a href="../admin/reports.php"
                        class="nav-link <?= ($currentPage == 'reports.php') ? 'active' : '' ?>">
                        <i class="bi bi-receipt me-2"></i>
                        <span>Reports</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="../admin/admin_settings.php"
                        class="nav-link <?= ($currentPage == 'admin_settings.php') ? 'active' : '' ?>">
                        <i class="bi bi-gear me-2"></i>
                        <span>Settings</span>
                    </a>
                </li>
                <li class="nav-item mt-4">
                    <a href="#" class="nav-link" onclick="confirmLogout()">
                        <i class="bi bi-box-arrow-right me-2"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
            <div class="sidebar-footer">
                <div class="sidebar-copy">
                    <small>&copy; <?= date('Y') ?> <?= htmlspecialchars(currentCompanyName()) ?></small>
                </div>
            </div>
        </div>
    </div>

    <div class="mobile-overlay" id="mobileOverlay"></div>

    <div class="main-content">
        <nav class="navbar top-navbar px-4">
            <div>
                <button class="btn btn-light" id="sidebarToggle"> <i class="bi bi-layout-sidebar"></i> </button>
            </div>
            <div class="ms-auto profile-box">
                <div class="profile-avatar">
                    <?php echo strtoupper(substr(htmlspecialchars($_SESSION['fullname']), 0, 1)); ?>
                </div>

                <div>
                    <div class="fw-semibold">
                        <?php echo htmlspecialchars($_SESSION['email']); ?>
                    </div>
                    <small class="text-muted text-uppercase">
                        <?php echo htmlspecialchars($_SESSION['role']); ?>
                    </small>
                </div>
            </div>
        </nav>
        <div class="page-content">

            <script>
                function confirmLogout() {
                    Swal.fire({
                        title: "Log Out?",
                        text: "Are you sure you want to log out?",
                        icon: "warning",
                        showCancelButton: true,
                        confirmButtonColor: "#dc3545",
                        cancelButtonColor: "#6c757d",
                        confirmButtonText: '<i class="bi bi-box-arrow-right"></i> Yes, Log Out',
                        cancelButtonText: "Cancel",
                        reverseButtons: true
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = "../accounts/acc_log_out.php";
                        }
                    });
                }
            </script>

            <?php
            /* The assistant. One line here puts it on every page this header serves. */
            include __DIR__ . '/../includes/chatbot/widget.php';
            ?>
