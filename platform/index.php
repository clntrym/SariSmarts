<?php

require_once __DIR__ . "/config.php";
include __DIR__ . "/header.php";


/*
|--------------------------------------------------------------------------
| PAGE CONTENT
|--------------------------------------------------------------------------
|
| Everything below is editable from the Super Admin website management
| screens. The page renders nothing for a section whose row is missing,
| so a half-configured section can never fatal the homepage.
|
*/

$hero = $conn->query("SELECT * FROM website_hero ORDER BY hero_id LIMIT 1")->fetch_assoc();

$heroBadges = $conn->query("
    SELECT icon, label
    FROM website_hero_badges
    WHERE status = 'Active'
    ORDER BY badge_order
");

$whySection = $conn->query("SELECT * FROM website_why_section ORDER BY section_id LIMIT 1")->fetch_assoc();

$whyCards = $conn->query("
    SELECT icon, title
    FROM website_why_cards
    WHERE status = 'Active'
    ORDER BY card_order
");

$modulesSection = $conn->query("SELECT * FROM website_modules_section ORDER BY section_id LIMIT 1")->fetch_assoc();

$modules = $conn->query("
    SELECT module_name, module_description, icon, icon_bg, icon_color
    FROM website_modules
    WHERE status = 'Active'
    ORDER BY module_order
");

$uspSection = $conn->query("SELECT * FROM website_usp_section ORDER BY section_id LIMIT 1")->fetch_assoc();

?>

<!-- =========================================================
     HERO
========================================================== -->

<?php if ($hero): ?>

    <section class="hero-section py-5">
        <div class="container">
            <div class="row align-items-center">

                <div class="col-lg-6">

                    <span class="hero-badge">
                        &#11088; <?= htmlspecialchars($hero['badge']) ?>
                    </span>

                    <h1 class="hero-title mt-4">
                        <?= htmlspecialchars($hero['title']) ?>
                    </h1>

                    <p class="hero-desc mt-4">
                        <?= htmlspecialchars($hero['description']) ?>
                    </p>

                    <div class="mt-5 d-flex gap-3 flex-wrap">

                        <a href="<?= htmlspecialchars($hero['primary_btn_link']) ?>"
                            class="btn btn-warning rounded-pill px-4 py-3">
                            <?= htmlspecialchars($hero['primary_btn_text']) ?> &rarr;
                        </a>

                        <a href="<?= htmlspecialchars($hero['secondary_btn_link']) ?>"
                            class="btn btn-outline-light rounded-pill px-4 py-3">
                            <?= htmlspecialchars($hero['secondary_btn_text']) ?>
                        </a>

                    </div>

                    <!-- Trust badges. Deliberately claims about the product,
                         not invented adoption figures. -->
                    <div class="row mt-5 g-3">

                        <?php while ($badge = $heroBadges->fetch_assoc()): ?>

                            <div class="col-6 col-lg-3">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="<?= htmlspecialchars($badge['icon']) ?> fs-5"></i>
                                    <small class="fw-semibold">
                                        <?= htmlspecialchars($badge['label']) ?>
                                    </small>
                                </div>
                            </div>

                        <?php endwhile; ?>

                    </div>

                </div>

                <div class="col-lg-6 text-center position-relative">
                    <img src="asset/11.jpg" class="img-fluid dashboard-img" alt="RetailSync dashboard">
                </div>

            </div>
        </div>
    </section>

<?php endif; ?>


<!-- =========================================================
     WHY RETAILCORE
========================================================== -->

<?php if ($whySection): ?>

    <section class="py-24 bg-white">
        <div class="max-w-7xl mx-auto px-6">

            <div class="text-center mb-16">

                <span
                    class="inline-block px-4 py-1 rounded-full border border-sky-300 bg-sky-100 text-sky-600 text-xs tracking-[3px] uppercase">
                    <?= htmlspecialchars($whySection['badge']) ?>
                </span>

                <h2 class="text-5xl font-bold text-slate-900 mt-5">
                    <?= htmlspecialchars($whySection['title']) ?>
                </h2>

                <p class="text-gray-500 text-lg mt-5 max-w-3xl mx-auto leading-8">
                    <?= htmlspecialchars($whySection['description']) ?>
                </p>

            </div>

            <div class="grid lg:grid-cols-4 md:grid-cols-2 gap-8">

                <?php while ($card = $whyCards->fetch_assoc()): ?>

                    <div
                        class="group bg-white border border-gray-200 rounded-3xl p-8 shadow-md text-center transition-all duration-500 hover:-translate-y-3 hover:shadow-2xl hover:border-sky-400">

                        <div
                            class="w-16 h-16 mx-auto rounded-2xl bg-sky-100 flex items-center justify-center text-sky-600 text-3xl transition-all duration-500 group-hover:bg-sky-500 group-hover:text-white">
                            <i class="<?= htmlspecialchars($card['icon']) ?>"></i>
                        </div>

                        <h3 class="text-xl font-bold mt-6 transition-colors duration-300 group-hover:text-sky-600">
                            <?= htmlspecialchars($card['title']) ?>
                        </h3>

                    </div>

                <?php endwhile; ?>

            </div>

        </div>
    </section>

<?php endif; ?>


<!-- =========================================================
     CORE MODULES
========================================================== -->

<?php if ($modulesSection): ?>

    <section class="py-24 bg-slate-50">
        <div class="max-w-7xl mx-auto px-6">

            <div class="text-center mb-16">

                <span
                    class="inline-block px-4 py-1 rounded-full border border-sky-300 bg-sky-100 text-sky-600 text-xs tracking-[3px] uppercase">
                    <?= htmlspecialchars($modulesSection['badge']) ?>
                </span>

                <h2 class="text-5xl font-bold text-slate-900 mt-5">
                    <?= htmlspecialchars($modulesSection['title']) ?>
                </h2>

                <p class="text-gray-500 mt-4 text-lg max-w-3xl mx-auto">
                    <?= htmlspecialchars($modulesSection['description']) ?>
                </p>

            </div>

            <div class="grid lg:grid-cols-4 md:grid-cols-2 gap-8">

                <?php while ($module = $modules->fetch_assoc()): ?>

                    <div
                        class="group bg-white rounded-3xl border border-gray-200 shadow-md p-8 transition-all duration-500 hover:-translate-y-3 hover:shadow-2xl hover:border-sky-400">

                        <div
                            class="w-14 h-14 rounded-2xl <?= htmlspecialchars($module['icon_bg']) ?> <?= htmlspecialchars($module['icon_color']) ?> flex items-center justify-center text-2xl transition-all duration-500 group-hover:scale-110">
                            <i class="<?= htmlspecialchars($module['icon']) ?>"></i>
                        </div>

                        <h3 class="text-xl font-bold mt-5 transition-colors duration-300 group-hover:text-sky-600">
                            <?= htmlspecialchars($module['module_name']) ?>
                        </h3>

                        <p class="text-gray-500 mt-3 leading-7">
                            <?= htmlspecialchars($module['module_description']) ?>
                        </p>

                    </div>

                <?php endwhile; ?>

            </div>

            <div class="text-center mt-14">
                <a href="features.php"
                    class="inline-flex items-center gap-2 bg-slate-900 text-white px-8 py-4 rounded-full font-semibold transition-all duration-300 hover:bg-sky-600 hover:gap-4">
                    Explore All Features
                    <i class="bi bi-arrow-right"></i>
                </a>
            </div>

        </div>
    </section>

<?php endif; ?>


<!-- =========================================================
     UNIQUE SELLING POINT
========================================================== -->

<?php if ($uspSection): ?>

    <section class="py-24 bg-slate-900">
        <div class="max-w-7xl mx-auto px-6">

            <div class="text-center mb-16">

                <span
                    class="inline-block px-4 py-1 rounded-full border border-sky-400 bg-sky-500/10 text-sky-300 text-xs tracking-[3px] uppercase">
                    <?= htmlspecialchars($uspSection['badge']) ?>
                </span>

                <h2 class="text-4xl md:text-5xl font-bold text-white mt-5">
                    <?= htmlspecialchars($uspSection['title']) ?>
                </h2>

                <p class="text-slate-400 text-lg mt-5 max-w-3xl mx-auto leading-8">
                    <?= htmlspecialchars($uspSection['description']) ?>
                </p>

            </div>


            <!-- Company -> branches -> central management hierarchy -->

            <div class="usp-tree text-center">

                <div class="usp-node usp-node-root">Company</div>

                <div class="usp-connector"></div>

                <div class="grid md:grid-cols-3 gap-6">

                    <?php foreach (['Branch 01', 'Branch 02', 'Branch 03'] as $branch): ?>

                        <div class="usp-branch">

                            <div class="usp-node usp-node-branch"><?= htmlspecialchars($branch) ?></div>

                            <ul class="usp-list">
                                <li>POS</li>
                                <li>Inventory</li>
                                <li>Staff</li>
                            </ul>

                        </div>

                    <?php endforeach; ?>

                </div>

                <div class="usp-connector"></div>

                <div class="usp-node usp-node-root">Central Management</div>

                <div class="usp-connector"></div>

                <div class="grid md:grid-cols-3 gap-6">

                    <?php foreach (
                        [
                            ['HR', 'bi bi-people'],
                            ['Finance', 'bi bi-cash-coin'],
                            ['Analytics', 'bi bi-bar-chart-line'],
                        ] as $leaf
                    ): ?>

                        <div class="usp-node usp-node-leaf">
                            <i class="<?= htmlspecialchars($leaf[1]) ?> me-2"></i>
                            <?= htmlspecialchars($leaf[0]) ?>
                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        </div>
    </section>

    <style>
        .usp-tree .usp-node {
            border-radius: 16px;
            padding: 16px 24px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .usp-tree .usp-node-root {
            background: #0ea5e9;
            color: #fff;
            font-size: 18px;
            min-width: 260px;
        }

        .usp-tree .usp-node-branch {
            background: #1e293b;
            border: 1px solid #334155;
            color: #fff;
            width: 100%;
        }

        .usp-tree .usp-node-leaf {
            background: #1e293b;
            border: 1px solid #334155;
            color: #e2e8f0;
            width: 100%;
        }

        .usp-tree .usp-connector {
            width: 2px;
            height: 36px;
            margin: 0 auto;
            background: linear-gradient(#0ea5e9, #334155);
        }

        .usp-tree .usp-list {
            list-style: none;
            margin: 12px 0 0;
            padding: 0;
            color: #94a3b8;
            font-size: 14px;
        }

        .usp-tree .usp-list li {
            padding: 4px 0;
        }
    </style>

<?php endif; ?>

<!-- =========================================================
     CLOSING CALL TO ACTION
========================================================== -->

<section class="py-24 bg-slate-50">
    <div class="max-w-4xl mx-auto px-6 text-center">

        <h2 class="text-4xl md:text-5xl font-bold text-slate-900">
            Ready to run your retail business from one platform?
        </h2>

        <p class="text-gray-500 text-lg mt-5">
            No setup fees, cancel anytime.
        </p>

        <div class="mt-10 flex flex-wrap gap-4 justify-center">

            <a href="pricing.php"
                class="bg-sky-500 text-white px-8 py-4 rounded-full font-semibold transition hover:bg-sky-600">
                View Pricing
            </a>

            <a href="bookDemo.php"
                class="border border-slate-300 text-slate-700 px-8 py-4 rounded-full font-semibold transition hover:bg-white">
                Book a Demo
            </a>

        </div>

    </div>
</section>


<?php
include __DIR__ . "/footer.php";
