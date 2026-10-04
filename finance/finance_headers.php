<?php

?>

<!DOCTYPE html>

<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Finance Department</title>

    <link rel="stylesheet" href="../bootstrap-5.3.8-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../bootstrap-icons-1.13.1/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../fontawesome-free-7.0.1-web/css/all.min.css">
    <script src="../bootstrap-5.3.8-dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- DataTables -->
    <link
        rel="stylesheet"
        href="https://cdn.datatables.net/2.3.3/css/dataTables.dataTables.min.css">

    <script src="https://cdn.datatables.net/2.3.3/js/dataTables.min.js"></script>

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
                <span>RetailCore</span>
            </h5>
            <small>Store POS</small>
        </div>

        <div class="mt-3">
            <ul class="nav flex-column mt-2">

                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link">
                        <i class="bi bi-grid-1x2 me-2"></i>
                        <span>Dashboard</span>
                    </a>
                </li>

                <small class="text-white-50 px-3">Operation</small>

                <!-- Income -->
                <li class="nav-item">
                    <a href="income.php" class="nav-link">
                        <i class="bi bi-graph-up-arrow me-2"></i>
                        <span>Income</span>
                    </a>
                </li>

                <!-- Expenses -->
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="bi bi-graph-down-arrow me-2"></i>
                        <span>Expenses</span>
                    </a>
                </li>

                <!-- Payroll -->
                <li class="nav-item">
                    <a href="#" class="nav-link">
                        <i class="bi bi-wallet2 me-2"></i>
                        <span>Payroll</span>
                    </a>
                </li>

                <!-- Cash Management -->
                <li class="nav-item">
                    <a href="approval.php" class="nav-link">
                        <i class="bi bi-cash-stack me-2"></i>
                        <span>Cash Management</span>
                    </a>
                </li>

                <!-- Account Payable -->
                <li class="nav-item">
                    <a href="attendance.php" class="nav-link">
                        <i class="bi bi-credit-card-2-front me-2"></i>
                        <span>Account Payable</span>
                    </a>
                </li>

                <!-- Account Receivable -->
                <li class="nav-item">
                    <a href="reports.php" class="nav-link">
                        <i class="bi bi-person-check me-2"></i>
                        <span>Account Receivable</span>
                    </a>
                </li>

                <!-- Budget -->
                <li class="nav-item">
                    <a href="hr_settings.php" class="nav-link">
                        <i class="bi bi-pie-chart me-2"></i>
                        <span>Budget</span>
                    </a>
                </li>

                <small class="text-white-50 px-3">Governance</small>

                <!-- Financial Report -->
                <li class="nav-item">
                    <a href="hr_settings.php" class="nav-link">
                        <i class="bi bi-bar-chart-line me-2"></i>
                        <span>Financial Report</span>
                    </a>
                </li>

                <!-- Journal Entries -->
                <li class="nav-item">
                    <a href="hr_settings.php" class="nav-link">
                        <i class="bi bi-journal-text me-2"></i>
                        <span>Journal Entries</span>
                    </a>
                </li>

                <!-- Approvals -->
                <li class="nav-item">
                    <a href="hr_settings.php" class="nav-link">
                        <i class="bi bi-check2-square me-2"></i>
                        <span>Approvals</span>
                    </a>
                </li>

                <!-- Transaction History -->
                <li class="nav-item">
                    <a href="hr_settings.php" class="nav-link">
                        <i class="bi bi-clock-history me-2"></i>
                        <span>Transaction History</span>
                    </a>
                </li>

                <!-- Audit Trail -->
                <li class="nav-item">
                    <a href="hr_settings.php" class="nav-link">
                        <i class="bi bi-shield-check me-2"></i>
                        <span>Audit Trail</span>
                    </a>
                </li>

                <!-- Logout -->
                <li class="nav-item mt-4">
                    <a href="#" class="nav-link">
                        <i class="bi bi-box-arrow-right me-2"></i>
                        <span>Logout</span>
                    </a>
                </li>

            </ul>

            <div class="sidebar-footer">
                <div class="sidebar-copy">
                    <small>© 2026 RetailCore</small>
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
                </div>

                <div>
                    <div class="fw-semibold">
                    </div>
                    <small class="text-muted text-uppercase">
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