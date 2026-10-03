<?php

require_once __DIR__ . "/config.php";
include __DIR__ . "/header.php";


/*
|--------------------------------------------------------------------------
| PLANS
|--------------------------------------------------------------------------
|
| Plans, their feature lists and their user roles all come from the
| Super Admin subscription screens, so pricing never has to be edited
| in code again.
|
*/

$plans = [];

$planResult = $conn->query("
    SELECT plan_id, plan_name, tagline, description, inherits_text,
           monthly_price, yearly_price, price_label,
           badge, button_text, button_color
    FROM subscription_plans
    WHERE status = 'Active'
    ORDER BY plan_order, plan_id
");

while ($plan = $planResult->fetch_assoc()) {
    $plan['features'] = [];
    $plan['roles'] = [];
    $plans[$plan['plan_id']] = $plan;
}

if (count($plans) > 0) {

    $featureResult = $conn->query("
        SELECT plan_id, feature_name
        FROM subscription_plan_features
        ORDER BY plan_id, feature_order, feature_id
    ");

    while ($feature = $featureResult->fetch_assoc()) {
        if (isset($plans[$feature['plan_id']])) {
            $plans[$feature['plan_id']]['features'][] = $feature['feature_name'];
        }
    }

    $roleResult = $conn->query("
        SELECT plan_id, role_name
        FROM subscription_plan_roles
        ORDER BY plan_id, role_order
    ");

    while ($role = $roleResult->fetch_assoc()) {
        if (isset($plans[$role['plan_id']])) {
            $plans[$role['plan_id']]['roles'][] = $role['role_name'];
        }
    }
}


/*
|--------------------------------------------------------------------------
| WHAT'S INCLUDED
|--------------------------------------------------------------------------
*/

$includedSection = $conn->query("SELECT * FROM website_included_section ORDER BY section_id LIMIT 1")->fetch_assoc();

$includedCards = [];

$cardResult = $conn->query("
    SELECT card_id, icon, icon_bg, icon_color, title, description
    FROM website_included_cards
    WHERE status = 'Active'
    ORDER BY card_order
");

while ($card = $cardResult->fetch_assoc()) {
    $card['features'] = [];
    $includedCards[$card['card_id']] = $card;
}

if (count($includedCards) > 0) {

    $featureResult = $conn->query("
        SELECT card_id, feature_name
        FROM website_included_features
        ORDER BY card_id, feature_order
    ");

    while ($feature = $featureResult->fetch_assoc()) {
        if (isset($includedCards[$feature['card_id']])) {
            $includedCards[$feature['card_id']]['features'][] = $feature['feature_name'];
        }
    }
}


/*
|--------------------------------------------------------------------------
| PAGE COPY
|--------------------------------------------------------------------------
|
| Headings, the plan comparison table and the FAQ come from the Super
| Admin pricing editor.
|
*/

$pricing = $conn->query("SELECT * FROM website_pricing_section ORDER BY section_id LIMIT 1")->fetch_assoc() ?: [];

$compareRows = $conn->query("
    SELECT feature_label, col1_value, col2_value, col3_value
    FROM website_pricing_compare
    WHERE status = 'Active'
    ORDER BY row_order, row_id
");

$faqs = $conn->query("
    SELECT faq_id, question, answer
    FROM website_pricing_faq
    WHERE status = 'Active'
    ORDER BY faq_order, faq_id
");

/* 'yes' draws a check, 'no' or blank a muted dash, anything else prints
   as written. Mirrors compareCell() in superAdmin/pricing.php. */
function pricingCompareCell($value)
{
    $value = trim($value);

    if (strcasecmp($value, 'yes') === 0) {
        return '<span class="text-success"><i class="fa-solid fa-check"></i></span>';
    }

    if ($value === '' || strcasecmp($value, 'no') === 0) {
        return '<span class="text-muted">&mdash;</span>';
    }

    return htmlspecialchars($value);
}

?>

<section class="pricing-hero">
    <div class="container">

        <span class="pricing-badge">
            <?= htmlspecialchars($pricing['hero_badge'] ?? 'PRICING') ?>
        </span>

        <h1 class="pricing-title mt-4">
            <?= htmlspecialchars($pricing['hero_title'] ?? '') ?>
        </h1>

        <p class="pricing-description mt-4">
            <?= nl2br(htmlspecialchars($pricing['hero_description'] ?? '')) ?>
        </p>

    </div>
</section>


<!-- =========================================================
     PLAN CARDS
========================================================== -->

<section class="py-5 bg-light">
    <div class="container">

        <div class="row g-4 align-items-stretch">

            <?php foreach ($plans as $plan): ?>

                <?php
                $isFeatured = ($plan['badge'] === 'Most Popular');
                ?>

                <div class="col-lg-4">

                    <div class="pricing-card h-100 <?= $isFeatured ? 'border-primary shadow-lg' : '' ?>"
                        style="<?= $isFeatured ? 'border:2px solid #0ea5e9;' : '' ?>">

                        <?php if ($plan['badge'] !== 'None' && $plan['badge'] !== ''): ?>
                            <span class="badge rounded-pill mb-3"
                                style="background:<?= htmlspecialchars($plan['button_color'] ?: '#0ea5e9') ?>;">
                                <?= htmlspecialchars($plan['badge']) ?>
                            </span>
                        <?php endif; ?>

                        <h3><?= htmlspecialchars($plan['plan_name']) ?></h3>

                        <?php if (!empty($plan['tagline'])): ?>
                            <div class="text-uppercase small fw-semibold text-primary mb-2">
                                <?= htmlspecialchars($plan['tagline']) ?>
                            </div>
                        <?php endif; ?>

                        <div class="price">
                            <?php if (!empty($plan['price_label'])): ?>
                                <?= htmlspecialchars($plan['price_label']) ?>
                            <?php else: ?>
                                &#8369;<?= number_format((float) $plan['monthly_price']) ?>
                                <span>/month</span>
                            <?php endif; ?>
                        </div>

                        <p class="text-muted">
                            <?= htmlspecialchars($plan['description']) ?>
                        </p>

                        <a href="register.php?plan=<?= (int) $plan['plan_id'] ?>" class="btn w-100 text-white mb-4"
                            style="background:<?= htmlspecialchars($plan['button_color'] ?: '#0ea5e9') ?>;">
                            <?= htmlspecialchars($plan['button_text']) ?>
                        </a>

                        <?php if (!empty($plan['inherits_text'])): ?>
                            <div class="fw-semibold text-dark mb-2">
                                <?= htmlspecialchars($plan['inherits_text']) ?>
                            </div>
                        <?php else: ?>
                            <div class="fw-semibold text-dark mb-2">Includes:</div>
                        <?php endif; ?>

                        <ul>
                            <?php foreach ($plan['features'] as $feature): ?>
                                <li><i class="fa-solid fa-check"></i> <?= htmlspecialchars($feature) ?></li>
                            <?php endforeach; ?>
                        </ul>

                        <?php if (count($plan['roles']) > 0): ?>

                            <div class="fw-semibold text-dark mt-4 mb-2">User Roles</div>

                            <ul class="list-unstyled small text-muted mb-0">
                                <?php foreach ($plan['roles'] as $role): ?>
                                    <li class="mb-1">
                                        <i class="bi bi-person-circle me-1"></i>
                                        <?= htmlspecialchars($role) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                        <?php endif; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </div>
</section>

<section class="pricing-comparison py-5">
    <div class="container">
        <div class="text-center mb-5">
            <span class="pricing-badge-light">
                <?= htmlspecialchars($pricing['compare_badge'] ?? 'COMPARE') ?>
            </span>
            <h2 class="comparison-title mt-3">
                <?= htmlspecialchars($pricing['compare_title'] ?? '') ?>
            </h2>
        </div>
        <div class="table-responsive">
            <table class="table comparison-table align-middle">
                <thead>
                    <tr>
                        <th>Feature</th>
                        <th class="text-center">
                            <?= htmlspecialchars($pricing['compare_col1'] ?? '') ?>
                        </th>
                        <th class="text-center">
                            <?= htmlspecialchars($pricing['compare_col2'] ?? '') ?>
                        </th>
                        <th class="text-center">
                            <?= htmlspecialchars($pricing['compare_col3'] ?? '') ?>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $compareRows->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['feature_label']) ?></td>
                            <td class="text-center"><?= pricingCompareCell($row['col1_value']) ?></td>
                            <td class="text-center"><?= pricingCompareCell($row['col2_value']) ?></td>
                            <td class="text-center"><?= pricingCompareCell($row['col3_value']) ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php if (!empty($pricing['compare_note'])): ?>
            <p class="text-center text-muted small mt-3">
                <?= htmlspecialchars($pricing['compare_note']) ?>
            </p>
        <?php endif; ?>
    </div>
</section>

<!-- =========================================================
     WHAT'S INCLUDED
========================================================== -->

<?php if ($includedSection && count($includedCards) > 0): ?>

    <section class="py-5 bg-light">
        <div class="container">

            <div class="text-center mb-5">

                <span class="pricing-badge-light">
                    <?= htmlspecialchars($includedSection['badge']) ?>
                </span>

                <h2 class="comparison-title mt-3">
                    <?= htmlspecialchars($includedSection['title']) ?>
                </h2>

                <?php if (!empty($includedSection['description'])): ?>
                    <p class="text-muted mt-3">
                        <?= htmlspecialchars($includedSection['description']) ?>
                    </p>
                <?php endif; ?>

            </div>

            <div class="row g-4">

                <?php foreach ($includedCards as $card): ?>

                    <div class="col-lg-3 col-md-6">

                        <div class="bg-white rounded-4 border h-100 p-4 shadow-sm">

                            <div class="d-inline-flex align-items-center justify-content-center rounded-3 mb-3 <?= htmlspecialchars($card['icon_bg']) ?> <?= htmlspecialchars($card['icon_color']) ?>"
                                style="width:52px;height:52px;font-size:24px;">
                                <i class="<?= htmlspecialchars($card['icon']) ?>"></i>
                            </div>

                            <h5 class="fw-bold mb-2">
                                <?= htmlspecialchars($card['title']) ?>
                            </h5>

                            <p class="text-muted small">
                                <?= htmlspecialchars($card['description']) ?>
                            </p>

                            <ul class="list-unstyled small mb-0">

                                <?php foreach ($card['features'] as $feature): ?>
                                    <li class="mb-1 d-flex gap-2">
                                        <i class="bi bi-check2 text-primary"></i>
                                        <span><?= htmlspecialchars($feature) ?></span>
                                    </li>
                                <?php endforeach; ?>

                            </ul>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>
    </section>

<?php endif; ?>


<section class="pricing-faq py-5">
    <div class="container">
        <div class="text-center mb-5">
            <span class="pricing-badge-light">
                <?= htmlspecialchars($pricing['faq_badge'] ?? 'FAQ') ?>
            </span>
            <h2 class="comparison-title mt-3">
                <?= htmlspecialchars($pricing['faq_title'] ?? '') ?>
            </h2>
        </div>
        <div class="accordion" id="pricingFAQ">

            <?php $firstFaq = true; ?>

            <?php while ($faq = $faqs->fetch_assoc()): ?>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button <?= $firstFaq ? '' : 'collapsed' ?>" type="button"
                            data-bs-toggle="collapse" data-bs-target="#faq<?= (int) $faq['faq_id'] ?>">
                            <?= htmlspecialchars($faq['question']) ?>
                        </button>
                    </h2>
                    <div id="faq<?= (int) $faq['faq_id'] ?>"
                        class="accordion-collapse collapse <?= $firstFaq ? 'show' : '' ?>"
                        data-bs-parent="#pricingFAQ">
                        <div class="accordion-body">
                            <?= nl2br(htmlspecialchars($faq['answer'])) ?>
                        </div>
                    </div>
                </div>

                <?php $firstFaq = false; ?>

            <?php endwhile; ?>

        </div>
    </div>
</section>

<?php include __DIR__ . "/footer.php"; ?>