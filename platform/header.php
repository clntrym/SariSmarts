<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SariSmart</title>
    <link rel="stylesheet" href="bootstrap-5.3.8-dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="fontawesome-free-7.0.1-web/css/all.min.css">
    <link rel="stylesheet" href="bootstrap-icons-1.13.1/bootstrap-icons.min.css">
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="bootstrap-5.3.8-dist/js/bootstrap.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.5/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body>
    <nav class="navbar navbar-expand-lg bg-white shadow-sm py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" id="navbar" href="index.php">
                <div class="bg-sky-500 rounded-4 p-3 me-3">
                    <i class="fa-solid fa-store text-white fs-5"></i>
                </div>
                <div>
                    <h5 class="fw-bold m-0 logo-text">SariSmart</h5>
                    <small class="text-secondary text-uppercase" style="letter-spacing:2px;">
                        Retail OS
                    </small>
                </div>
            </a>
            <button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#navbar">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="navbar-collapse d-flex justify-content-between align-items-center">
                <ul class="navbar-nav mx-auto">
                    <li class="nav-item">
                        <a class="nav-link px-3" href="platform.php">Platform</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link px-3" href="marketPlace.php">Marketplace</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link px-3" href="features.php">Features</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link px-3" href="pricing.php">Pricing</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link px-3" href="careers.php">Careers</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle px-3" href="#" data-bs-toggle="dropdown">
                        </a>
                        <ul class="dropdown-menu shadow border-0 rounded-4">
                            <li><a class="dropdown-item" href="homePage/aboutUs.php">About Us</a></li>
                            <li><a class="dropdown-item" href="#">Support</a></li>
                            <li><a class="dropdown-item" href="contactUs.php">Contact</a></li>
                        </ul>
                    </li>
                </ul>
                <div class="d-flex align-items-center gap-3">
                    <a href="<?= (isset($isLocal) && $isLocal) ? '/SariSmarts/accounts/acc_log_in.php' : '/accounts/acc_log_in.php' ?>"
                        class="text-dark text-decoration-none fw-semibold">
                        Login
                    </a>
                    <button class="btn btn-primary rounded-pill px-4 py-2">
                        Get Started
                    </button>
                </div>
            </div>
        </div>
    </nav>