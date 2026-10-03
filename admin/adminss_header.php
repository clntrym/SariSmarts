<?php
require_once("../init.php");
requireRole(['admin']);

$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>

<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>NCST Sari-Sari Store</title>

    <link rel="stylesheet" href="../bootstrap-5.3.8-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../bootstrap-icons-1.13.1/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../fontawesome-free-7.0.1-web/css/all.min.css">
    <script src="../bootstrap-5.3.8-dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">
    <script src="https://cdn.datatables.net/2.3.3/js/dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/2.3.3/js/dataTables.bootstrap5.min.js"></script>
    <link rel="stylesheet" href="../assets/css/datatable-theme.css">
    <script src="../assets/js/datatable-theme.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/datatable-theme.js') ?>"></script>
    <link rel="stylesheet" href="../assets/leaflet/leaflet.css">

    <style>
        :root {
            --sidebar-width: 240px;
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
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link <?= ($currentPage == 'dashboard.php') ? 'active' : '' ?>">
                        <i class="bi bi-grid me-2"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="user_management.php"
                        class="nav-link <?= ($currentPage == 'user_management.php') ? 'active' : '' ?>">
                        <i class="bi bi-map me-2"></i>
                        <span>User Management</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="branch.php" class="nav-link <?= ($currentPage == 'branch.php') ? 'active' : '' ?>">
                        <i class="bi bi-map me-2"></i>
                        <span>Branch</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="approval.php" class="nav-link <?= ($currentPage == 'approval.php') ? 'active' : '' ?>">
                        <i class="bi bi-check2-circle me-2"></i>
                        <span>Approval</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="stock_requests.php"
                        class="nav-link <?= ($currentPage == 'stock_requests.php') ? 'active' : '' ?>">
                        <i class="bi bi-box-arrow-up me-2"></i>
                        <span>Stock Request</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="Inventory.php" class="nav-link <?= ($currentPage == 'Inventory.php') ? 'active' : '' ?>">
                        <i class="bi bi-box-seam me-2"></i>
                        <span>Inventory</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="suppliers.php" class="nav-link <?= ($currentPage == 'suppliers.php') ? 'active' : '' ?>">
                        <i class="bi bi-truck me-2"></i>
                        <span>Suppliers</span>
                    </a>
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