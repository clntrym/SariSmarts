<?php
include __DIR__ . "/header.php";
require_once 'config.php';

$platform = mysqli_fetch_assoc(mysqli_query($conn, "
SELECT *
FROM website_platform_section
LIMIT 1
"));

$cards = mysqli_query($conn, "
    SELECT *
    FROM website_platform_cards
    WHERE status='Active'
    ORDER BY card_order ASC
");

$moduleSection = mysqli_fetch_assoc(mysqli_query($conn, "
SELECT *
FROM website_modules_section
LIMIT 1
"));

$modules = mysqli_query($conn, "
    SELECT *
    FROM website_modules
    WHERE status='Active'
    ORDER BY module_order ASC
");

?>
<section class="pricing-hero ">
    <div class="container ">
        <span class="pricing-badge">
            PLATFORM
        </span>
        <h1 class="pricing-title mt-4">
            <?= htmlspecialchars($platform['hero_title']) ?>
        </h1>
        <p class="pricing-description mt-4">
            <?= nl2br(htmlspecialchars($platform['hero_description'])) ?>
        </p>
    </div>
</section>

<section class="py-24 bg-slate-50">
    <div class="max-w-7xl mx-auto px-6">
        <div class="text-center mb-16">
            <span
                class="inline-block px-4 py-1 rounded-full border border-sky-300 bg-sky-100 text-sky-600 text-xs tracking-[3px] uppercase">
                <?= htmlspecialchars($platform['section_badge']) ?>
            </span>
            <h2 class="text-5xl font-bold text-slate-900 mt-5">
                <?= htmlspecialchars($platform['section_title']) ?>
            </h2>
            <p class="text-gray-500 text-lg mt-3 max-w-3xl mx-auto leading-8">
                <?= htmlspecialchars($platform['section_description']) ?>
            </p>
        </div>
        <div class="grid md:grid-cols-2 xl:grid-cols-4 gap-6">
            <?php while ($card = mysqli_fetch_assoc($cards)) { ?>
                <div class="bg-white border border-gray-200 rounded-3xl p-7 shadow-sm">
                    <div class="flex justify-between items-start mb-6">
                        <div
                            class="w-16 h-16 rounded-2xl bg-sky-100 flex items-center justify-center text-sky-600 text-3xl">
                            <i class="<?= htmlspecialchars($card['icon']); ?>"></i>
                        </div>
                        <span class="text-5xl font-black text-gray-200">
                            <?= str_pad($card['card_order'], 2, "0", STR_PAD_LEFT); ?>
                        </span>
                    </div>
                    <h3 class="text-2xl font-bold text-slate-900 mb-3">
                        <?= htmlspecialchars($card['title']); ?>
                    </h3>
                    <p class="text-gray-500 leading-7">
                        <?= htmlspecialchars($card['description']); ?>
                    </p>
                </div>
            <?php } ?>
        </div>
    </div>
</section>
<section class="py-24 bg-slate-50">
    <div class="max-w-7xl mx-auto px-6">
        <div class="text-center mb-16">
            <span
                class="inline-block px-4 py-1 rounded-full border border-sky-300 bg-sky-100 text-sky-600 text-xs tracking-[3px] uppercase">
                <?= htmlspecialchars($moduleSection['badge']) ?>
            </span>
            <h2 class="text-5xl font-bold text-slate-900 mt-5">
                <?= htmlspecialchars($moduleSection['title']) ?>
            </h2>
            <p class="text-gray-500 mt-4 text-lg">
                <?= htmlspecialchars($moduleSection['description']) ?>
            </p>
        </div>
        <div class="grid md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <?php while ($module = mysqli_fetch_assoc($modules)) { ?>
                <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
                    <div
                        class="w-14 h-14 rounded-xl <?= htmlspecialchars($module['icon_bg']) ?> flex items-center justify-center <?= htmlspecialchars($module['icon_color']) ?> text-2xl mb-5">
                        <i class="<?= htmlspecialchars($module['icon']) ?>"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-slate-900">
                        <?= htmlspecialchars($module['module_name']) ?>
                    </h3>
                    <p class="text-gray-500 mt-2 text-sm">
                        <?= htmlspecialchars($module['module_description']) ?>
                    </p>
                </div>
            <?php } ?>
        </div>
    </div>
</section>
<?php
include __DIR__ . "/footer.php";
?>