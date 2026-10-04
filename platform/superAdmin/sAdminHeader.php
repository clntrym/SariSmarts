<?php

/*
|--------------------------------------------------------------------------
| PLATFORM LAYOUT
|--------------------------------------------------------------------------
|
| Shared by every platform module. Access is no longer decided here: each
| module opens with requirePlatformAccess('<module>') above its own request
| handling, because a page that writes before this include is reached would
| otherwise have already done the work by the time it was told no.
|
| What is left here is the check that somebody from the platform side is
| signed in at all, so a stray direct hit on this file cannot render the
| shell, and the sidebar that follows shows only what their role can open.
|
*/

require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../includes/platform_roles.php';
require_once __DIR__ . '/../includes/platform_feed.php';

requireRole(array_keys(platformRoles()));

$current_page = basename($_SERVER['PHP_SELF']);

/* Set by requirePlatformAccess() when it turned somebody away. */
$access_denied = $_SESSION['platform_denied'] ?? null;
unset($_SESSION['platform_denied']);

/*
| The sidebar and the topbar both show who is signed in, so this is worked
| out once here rather than halfway down the file where only one of them
| could reach it. Initials need no image file and always render.
*/
$saName = $_SESSION['fullname'] ?? 'Platform Staff';

$saInitials = '';
foreach (preg_split('/\s+/', trim($saName)) as $part) {
    if ($part !== '' && strlen($saInitials) < 2) {
        $saInitials .= strtoupper($part[0]);
    }
}
$saInitials = $saInitials !== '' ? $saInitials : 'PS';

/*
| The bell. Built here because the topbar below needs both the count and
| the list, and because a query belongs above the markup that uses it.
|
| platformFeed() reads live rows from the database both apps share, so an
| application submitted or an account created inside RetailCore shows up
| here without anything being pushed across.
*/
$bellItems = platformFeed($conn);
$bellNow = platformNow($conn);

$bellSeenAt = null;

if (!empty($_SESSION['user_id'])) {
    $seenStmt = $conn->prepare("SELECT notifications_seen_at FROM users WHERE user_id = ? LIMIT 1");
    $seenStmt->bind_param("i", $_SESSION['user_id']);
    $seenStmt->execute();
    $bellSeenAt = $seenStmt->get_result()->fetch_assoc()['notifications_seen_at'] ?? null;
    $seenStmt->close();
}

$bellUnread = platformFeedUnread($bellItems, $bellSeenAt, $bellNow);
?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>RetailCore Super Admin</title>

    <!-- Bootstrap -->
    <link rel="stylesheet" href="<?= $BASE_URL ?>/bootstrap-5.3.8-dist/css/bootstrap.min.css">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="<?= $BASE_URL ?>/bootstrap-icons-1.13.1/bootstrap-icons.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="<?= $BASE_URL ?>/bootstrap-5.3.8-dist/js/bootstrap.bundle.min.js"></script>


    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- SweetAlert2. The subscription screens call Swal.fire() in about
         twenty places; without this every one of them threw. -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- DataTables + the shared table theme, so Super Admin tables match
         the rest of the system. -->
    <link rel="stylesheet" href="https://cdn.datatables.net/2.3.3/css/dataTables.bootstrap5.min.css">
    <script src="https://cdn.datatables.net/2.3.3/js/dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/2.3.3/js/dataTables.bootstrap5.min.js"></script>
    <link rel="stylesheet" href="<?= $BASE_URL ?>/assets/css/datatable-theme.css">
    <script src="<?= $BASE_URL ?>/assets/js/datatable-theme.js"></script>

    <!-- Validation and confirmation for every form on these screens.
         Loaded after SweetAlert2 and Bootstrap, both of which it uses. -->
    <script src="<?= $BASE_URL ?>/assets/js/sadmin-forms.js"></script>

    <!-- Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">

    <!-- The Super Admin design system: shell, panels, stat tiles,
         tables, buttons and form states for every module. -->
    <link rel="stylesheet" href="<?= $BASE_URL ?>/assets/css/sadmin.css">

</head>

<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-box">
                <div class="logo-icon">
                    <i class="bi bi-shield-check"></i>
                </div>
                <div>
                    <h2 class="logo-title">RetailCore</h2>
                    <div class="logo-sub"><?= htmlspecialchars(platformRoleLabel()) ?></div>
                </div>
            </div>
        </div>

        <?php
        /*
        | The sidebar is built from this map rather than written out by hand,
        | so a module is listed once and its visibility follows the role
        | registry automatically. A group with nothing visible in it is not
        | drawn at all: an empty heading tells a Finance user there is a
        | Website section they cannot reach, which is worse than silence.
        |
        | Each item is [module key, path under superAdmin, icon, label].
        */
        $nav = [

            'Platform' => [
                ['dashboard', 'dashboard.php', 'bi-grid', 'Dashboard'],
                ['submenu', 'websiteMenu', 'bi-globe', 'Website Management', [
                    ['homePage', 'homePage.php', 'bi-house', 'Homepage'],
                    ['platformSection', 'website_management/platformSection.php', 'bi-diagram-3', 'Platform'],
                    ['heroBanner', 'heroBanner.php', 'bi-image', 'Hero Banner'],
                    ['features', 'features.php', 'bi-stars', 'Features'],
                    ['pricing', 'pricing.php', 'bi-tags', 'Pricing'],
                    ['careers', 'careers.php', 'bi-briefcase', 'Careers'],
                    ['footer', 'footer.php', 'bi-layout-text-window-reverse', 'Footer'],
                ]],
            ],

            'People &amp; Sales' => [
                ['leads', 'leads.php', 'bi-person-lines-fill', 'Leads'],
                ['employees', 'employees.php', 'bi-person-vcard', 'Employees'],
                ['support', 'support.php', 'bi-headset', 'Customer Support'],
                ['notifications', 'notifications.php', 'bi-bell', 'Notifications'],
            ],

            'Business' => [
                ['companyReview', 'companyReview.php', 'bi-clipboard-check', 'Applications'],
                ['company', 'company.php', 'bi-buildings', 'Companies'],
                ['marketplace', 'marketplace.php', 'bi-shop-window', 'Marketplace'],
            ],

            'Money' => [
                ['subscriptionManagement', 'subscriptionManagement.php', 'bi-credit-card', 'Subscription'],
                ['billing', 'billing.php', 'bi-receipt', 'Billing'],
                ['reports', 'reports.php', 'bi-bar-chart', 'Reports'],
            ],

            'System' => [
                ['users', 'users.php', 'bi-people', 'User Management'],
                ['audit', 'audit.php', 'bi-file-earmark-text', 'Audit Logs'],
                ['settings', 'settings.php', 'bi-gear', 'Settings'],
            ],

        ];

        /* An item is visible when the role holds it; a submenu when it holds
           at least one child. */
        $navVisible = function (array $item): bool {
            if ($item[0] !== 'submenu') {
                return platformCan($item[0]);
            }

            foreach ($item[4] as $child) {
                if (platformCan($child[0])) {
                    return true;
                }
            }

            return false;
        };
        ?>

        <div class="sidebar-scroll">

            <?php $firstGroup = true; ?>

            <?php foreach ($nav as $groupName => $items): ?>

                <?php $shown = array_values(array_filter($items, $navVisible)); ?>

                <?php if (count($shown) === 0) { continue; } ?>

                <div class="menu-title <?= $firstGroup ? '' : 'mt-4' ?>">
                    <?= $groupName ?>
                </div>

                <?php $firstGroup = false; ?>

                <?php foreach ($shown as $item): ?>

                    <?php if ($item[0] !== 'submenu'): ?>

                        <a href="<?= $BASE_URL ?>/superAdmin/<?= $item[1] ?>"
                            class="menu-item <?= $current_page === basename($item[1]) ? 'active' : '' ?>">
                            <i class="bi <?= $item[2] ?>"></i>
                            <span><?= $item[3] ?></span>
                        </a>

                    <?php else: ?>

                        <button class="menu-item w-100 border-0 bg-transparent text-white text-start" type="button"
                            data-bs-toggle="collapse" data-bs-target="#<?= $item[1] ?>">
                            <i class="bi <?= $item[2] ?>"></i>
                            <span><?= $item[3] ?></span>
                            <i class="bi bi-chevron-down ms-auto"></i>
                        </button>

                        <div class="collapse submenu" id="<?= $item[1] ?>">
                            <?php foreach ($item[4] as $child): ?>
                                <?php if (!platformCan($child[0])) { continue; } ?>
                                <a href="<?= $BASE_URL ?>/superAdmin/<?= $child[1] ?>"
                                    class="<?= $current_page === basename($child[1]) ? 'active-sub' : '' ?>">
                                    <i class="bi <?= $child[2] ?> me-2"></i><?= $child[3] ?>
                                </a>
                            <?php endforeach; ?>
                        </div>

                    <?php endif; ?>

                <?php endforeach; ?>

            <?php endforeach; ?>

        </div>

        <!-- Profile -->

        <div class="sidebar-profile">

            <div class="profile-card">

                <div class="profile-avatar">
                    <?= htmlspecialchars($saInitials) ?>
                </div>

                <div>

                    <div class="profile-name">
                        <?= htmlspecialchars($saName) ?>
                    </div>

                    <div class="profile-email">
                        <?= htmlspecialchars(platformRoleLabel()) ?>
                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- ===========================
        MAIN WRAPPER
=========================== -->

    <div class="main-wrapper">

        <!-- Topbar -->

        <div class="topbar">

            <div class="d-flex align-items-center">

                <button class="btn btn-light d-lg-none me-3" id="toggleSidebar">

                    <i class="bi bi-list fs-4"></i>

                </button>

                <h4 class="fw-bold m-0">
                    Super Admin
                </h4>

            </div>

            <div class="d-flex align-items-center gap-3">

                <button class="btn btn-light rounded-circle">

                    <i class="bi bi-search"></i>

                </button>

                <div class="dropdown">

                    <button class="btn btn-light rounded-circle position-relative" type="button"
                        data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"
                        title="<?= $bellUnread ?> unread">

                        <i class="bi bi-bell"></i>

                        <?php if ($bellUnread > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?= $bellUnread > 9 ? '9+' : $bellUnread ?>
                            </span>
                        <?php endif; ?>

                    </button>

                    <div class="dropdown-menu dropdown-menu-end shadow border-0 mt-2 sa-bell"
                        style="width:360px;max-height:440px;overflow:auto;">

                        <div class="d-flex justify-content-between align-items-center px-3 py-2">
                            <span class="fw-semibold" style="color:#00224c;">Notifications</span>
                            <span class="sa-muted"><?= $bellUnread ?> unread</span>
                        </div>

                        <hr class="dropdown-divider my-0">

                        <?php if (count($bellItems) === 0): ?>

                            <div class="px-3 py-4 text-center sa-muted">
                                <i class="bi bi-check2-circle d-block mb-2" style="font-size:24px;"></i>
                                Nothing needs you right now.
                            </div>

                        <?php else: ?>

                            <?php foreach ($bellItems as $item): ?>

                                <?php
                                /* One rule, shared with the badge count. */
                                $isNew = platformFeedIsNew($item, $bellSeenAt, $bellNow);
                                ?>

                                <a class="d-flex gap-2 px-3 py-2 text-decoration-none sa-bell-item<?= $isNew ? ' sa-bell-new' : '' ?>"
                                    href="<?= $BASE_URL ?>/superAdmin/<?= htmlspecialchars($item['url']) ?>">

                                    <span class="sa-bell-dot sa-tone-<?= htmlspecialchars($item['tone']) ?>">
                                        <i class="bi <?= htmlspecialchars($item['icon']) ?>"></i>
                                    </span>

                                    <span class="flex-grow-1">
                                        <span class="d-block sa-name"><?= htmlspecialchars($item['title']) ?></span>
                                        <span class="d-block small text-muted"><?= htmlspecialchars($item['detail']) ?></span>
                                        <span class="d-block sa-muted"><?= htmlspecialchars(platformFeedWhen($item, $bellNow)) ?></span>
                                    </span>

                                </a>

                            <?php endforeach; ?>

                            <hr class="dropdown-divider my-0">

                            <form method="POST" action="<?= $BASE_URL ?>/superAdmin/notifications_seen.php"
                                class="px-3 py-2" data-sa-skip>
                                <input type="hidden" name="back"
                                    value="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
                                <button type="submit" class="btn btn-sm sa-btn-soft w-100">
                                    Mark all as read
                                </button>
                            </form>

                        <?php endif; ?>

                    </div>

                </div>

                <div class="dropdown">

                    <button class="btn p-0 border-0 bg-transparent d-flex align-items-center gap-2"
                        type="button" data-bs-toggle="dropdown" aria-expanded="false">

                        <span class="sa-avatar"><?= htmlspecialchars($saInitials) ?></span>

                        <span class="d-none d-md-block text-start lh-sm">
                            <span class="d-block fw-semibold" style="font-size:14px;color:#00224c;">
                                <?= htmlspecialchars($saName) ?>
                            </span>
                            <span class="d-block text-muted" style="font-size:12px;">
                                <?= htmlspecialchars(platformRoleLabel()) ?>
                            </span>
                        </span>

                        <i class="bi bi-chevron-down text-muted" style="font-size:12px;"></i>

                    </button>

                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2"
                        style="border-radius:12px;min-width:220px;">

                        <li class="px-3 py-2">
                            <div class="sa-name">
                                <?= htmlspecialchars($saName) ?>
                            </div>
                            <div class="text-muted" style="font-size:12px;">
                                <?= htmlspecialchars($_SESSION['email'] ?? '') ?>
                            </div>
                        </li>

                        <li><hr class="dropdown-divider"></li>

                        <li>
                            <a class="dropdown-item" href="<?= $BASE_URL ?>/index.php" target="_blank">
                                <i class="bi bi-box-arrow-up-right me-2"></i> View website
                            </a>
                        </li>

                        <li>
                            <a class="dropdown-item text-danger" href="<?= $BASE_URL ?>/accounts/acc_log_out.php">
                                <i class="bi bi-box-arrow-right me-2"></i> Sign out
                            </a>
                        </li>

                    </ul>

                </div>

            </div>

        </div>

        <div class="page-content">