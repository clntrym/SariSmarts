<?php

require_once __DIR__ . "/config.php";
include __DIR__ . "/header.php";


/*
|--------------------------------------------------------------------------
| FEATURES - the same core modules as the homepage, shown full width
|--------------------------------------------------------------------------
*/

$modulesSection = $conn->query("SELECT * FROM website_modules_section ORDER BY section_id LIMIT 1")->fetch_assoc();

$modules = $conn->query("
    SELECT module_name, module_description, icon, icon_bg, icon_color
    FROM website_modules
    WHERE status = 'Active'
    ORDER BY module_order
");

$moduleCount = $modules ? $modules->num_rows : 0;

?>

<section class="pricing-hero">
    <div class="container">

        <span class="pricing-badge">
            FEATURES
        </span>

        <h1 class="pricing-title mt-4">
            <?= $moduleCount ?> core modules that run your whole retail business
        </h1>

        <p class="pricing-description mt-4">
            <?= htmlspecialchars($modulesSection['description'] ?? '') ?>
        </p>

    </div>
</section>


<section class="bg-slate-50 py-16">
    <div class="mx-auto max-w-7xl px-6">

        <div class="grid gap-8 md:grid-cols-2">

            <?php while ($module = $modules->fetch_assoc()): ?>

                <div
                    class="rounded-3xl border border-slate-200 bg-white p-10 shadow-sm transition duration-500 hover:-translate-y-2 hover:shadow-xl hover:border-sky-400">

                    <div class="flex items-start gap-6">

                        <div
                            class="w-20 h-20 shrink-0 rounded-2xl <?= htmlspecialchars($module['icon_bg']) ?> <?= htmlspecialchars($module['icon_color']) ?> flex items-center justify-center text-4xl">
                            <i class="<?= htmlspecialchars($module['icon']) ?>"></i>
                        </div>

                        <div class="flex-1">

                            <h3 class="text-2xl font-bold text-slate-900">
                                <?= htmlspecialchars($module['module_name']) ?>
                            </h3>

                            <p class="mt-3 text-slate-500 leading-8">
                                <?= htmlspecialchars($module['module_description']) ?>
                            </p>

                        </div>

                    </div>

                </div>

            <?php endwhile; ?>

        </div>


        <div class="text-center mt-16">

            <a href="pricing.php"
                class="inline-flex items-center gap-2 bg-slate-900 text-white px-8 py-4 rounded-full font-semibold transition-all duration-300 hover:bg-sky-600 hover:gap-4">
                See Plans &amp; Pricing
                <i class="bi bi-arrow-right"></i>
            </a>

        </div>

    </div>
</section>


<?php
include __DIR__ . "/footer.php";
