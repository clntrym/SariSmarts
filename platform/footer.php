<?php

/*
|--------------------------------------------------------------------------
| SITE FOOTER
|--------------------------------------------------------------------------
|
| Included by every public page and edited from Super Admin > Website
| Management > Footer.
|
| config.php is pulled in here rather than assumed: pages such as
| aboutUs.php and contactUs.php include this file without ever touching
| the database themselves. require_once makes it a no-op everywhere else.
|
*/

require_once __DIR__ . '/config.php';

$footerContent = $conn->query("SELECT * FROM website_footer ORDER BY footer_id LIMIT 1")->fetch_assoc() ?: [];

$footerColumns = [];

$footerColumnResult = $conn->query("
    SELECT column_id, heading
    FROM website_footer_columns
    WHERE status = 'Active'
    ORDER BY column_order, column_id
");

while ($footerColumn = $footerColumnResult->fetch_assoc()) {
    $footerColumn['links'] = [];
    $footerColumns[$footerColumn['column_id']] = $footerColumn;
}

if (count($footerColumns) > 0) {

    $footerLinkResult = $conn->query("
        SELECT column_id, label, url
        FROM website_footer_links
        WHERE status = 'Active'
        ORDER BY column_id, link_order, link_id
    ");

    while ($footerLink = $footerLinkResult->fetch_assoc()) {
        if (isset($footerColumns[$footerLink['column_id']])) {
            $footerColumns[$footerLink['column_id']]['links'][] = $footerLink;
        }
    }
}

$footerLegalLinks = $conn->query("
    SELECT label, url
    FROM website_footer_legal_links
    WHERE status = 'Active'
    ORDER BY link_order, legal_id
");

$footerCopyright = str_replace(
    '{year}',
    date('Y'),
    $footerContent['copyright_text'] ?? ''
);

?>

<div class="py-24 from-slate-900 via-sky-900 to-slate-900">
    <div class="max-w-7xl mx-auto px-6">
        <div class="bg-white/10 bg-gradient-to-r rounded-[40px] border border-white/20 p-12 lg:p-20 text-center">

            <?php if (!empty($footerContent['cta_badge'])): ?>
                <span class="inline-block px-5 py-2 rounded-full bg-white/20 text-sky-200 text-xs tracking-[3px] uppercase">
                    <?= htmlspecialchars($footerContent['cta_badge']) ?>
                </span>
            <?php endif; ?>

            <h2 class="text-5xl lg:text-6xl font-extrabold text-white mt-8 leading-tight">
                <?= htmlspecialchars($footerContent['cta_title'] ?? '') ?>
                <?php if (!empty($footerContent['cta_title_highlight'])): ?>
                    <br>
                    <span class="text-sky-300">
                        <?= htmlspecialchars($footerContent['cta_title_highlight']) ?>
                    </span>
                <?php endif; ?>
            </h2>

            <p class="text-slate-300 text-lg max-w-3xl mx-auto mt-8 leading-8">
                <?= nl2br(htmlspecialchars($footerContent['cta_description'] ?? '')) ?>
            </p>

            <div class="flex flex-col sm:flex-row justify-center gap-5 mt-12">
                <a href="<?= htmlspecialchars($footerContent['cta_button_link'] ?? '#') ?>" class="px-8 py-4 rounded-full
                        border border-sky-400/50
                        text-white
                        font-semibold
                        text-lg
                        backdrop-blur-sm
                        transition-all
                        duration-300
                        hover:bg-sky-500
                        hover:border-sky-500
                        hover:shadow-xl
                        hover:shadow-sky-500/40
                        hover:-translate-y-1
                        active:scale-95">
                    <i class="fa-solid fa-rocket mr-2"></i>
                    <?= htmlspecialchars($footerContent['cta_button_text'] ?? 'Get Started') ?>
                </a>
            </div>

        </div>
    </div>
</div>

<footer class="bg-[#0B2340] text-white pt-20 pb-10">
    <div class="container">
        <div class="row">

            <div class="col-lg-3 col-md-6 mb-5">

                <div class="d-flex align-items-center mb-4">
                    <div class="bg-sky-500 rounded-xl p-3 me-3">
                        <i class="fa-solid fa-store text-white text-xl"></i>
                    </div>
                    <h3 class="fw-bold fs-3 m-0">
                        <?= htmlspecialchars($footerContent['brand_name'] ?? 'SariSmart') ?>
                    </h3>
                </div>

                <p class="text-gray-300 leading-8">
                    <?= nl2br(htmlspecialchars($footerContent['brand_description'] ?? '')) ?>
                </p>

                <div class="mt-4">
                    <?php if (!empty($footerContent['contact_email'])): ?>
                        <p class="mb-1"><?= htmlspecialchars($footerContent['contact_email']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($footerContent['contact_phone'])): ?>
                        <p><?= htmlspecialchars($footerContent['contact_phone']) ?></p>
                    <?php endif; ?>
                </div>

            </div>

            <?php foreach ($footerColumns as $column): ?>

                <div class="col-lg-2 col-md-6 mb-5">

                    <h5 class="fw-bold mb-4">
                        <?= htmlspecialchars($column['heading']) ?>
                    </h5>

                    <ul class="list-unstyled">
                        <?php foreach ($column['links'] as $i => $link): ?>
                            <li class="<?= $i === count($column['links']) - 1 ? '' : 'mb-3' ?>">
                                <a href="<?= htmlspecialchars($link['url']) ?>" class="footer-link">
                                    <?= htmlspecialchars($link['label']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                </div>

            <?php endforeach; ?>

        </div>

        <hr class="border-secondary my-5">

        <div class="d-flex justify-content-between align-items-center flex-wrap">

            <p class="text-gray-400 mb-3 mb-lg-0">
                <?= htmlspecialchars($footerCopyright) ?>
            </p>

            <div class="d-flex gap-4">
                <?php while ($legal = $footerLegalLinks->fetch_assoc()): ?>
                    <a href="<?= htmlspecialchars($legal['url']) ?>" class="footer-link">
                        <?= htmlspecialchars($legal['label']) ?>
                    </a>
                <?php endwhile; ?>
            </div>

        </div>

    </div>
</footer>


<?php
/*
|--------------------------------------------------------------------------
| THE INQUIRY ASSISTANT
|--------------------------------------------------------------------------
|
| In the footer, so it is on every public page rather than only the landing
| page: somebody reading the pricing page is the likeliest person to have a
| question.
|
| Nothing here knows the API key. The widget talks to chat.php, which is
| the only thing that does.
|
| Hidden on the registration and subscription screens. Somebody halfway
| through a form with their DTI certificate in hand is not browsing, and a
| bubble over the submit button is in the way.
*/
$chatHiddenOn = ['register.php', 'resubmit.php', 'subscribe.php', 'subscribe_callback.php'];
$chatHere = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
?>

<?php if (!in_array($chatHere, $chatHiddenOn, true)): ?>

    <style>
        #sarismartChat {
            position: fixed;
            right: 20px;
            bottom: 20px;
            z-index: 1050;
            font-family: 'Poppins', system-ui, sans-serif;
        }

        .sc-toggle {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            border: none;
            background: #00224c;
            color: #fff;
            font-size: 24px;
            box-shadow: 0 8px 24px rgba(0, 34, 76, .3);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform .15s ease;
        }

        .sc-toggle:hover {
            transform: scale(1.05);
        }

        .sc-is-open .sc-toggle {
            display: none;
        }

        .sc-panel {
            display: none;
            flex-direction: column;
            width: 360px;
            max-width: calc(100vw - 40px);
            height: 520px;
            max-height: calc(100vh - 40px);
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 18px 50px rgba(0, 0, 0, .22);
            overflow: hidden;
        }

        .sc-panel.sc-open {
            display: flex;
        }

        .sc-head {
            background: #00224c;
            color: #fff;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .sc-head strong {
            font-size: 15px;
        }

        .sc-head div {
            font-size: 12px;
            color: #cbd5e1;
        }

        .sc-close {
            background: none;
            border: none;
            color: #fff;
            font-size: 20px;
            line-height: 1;
            cursor: pointer;
            padding: 0 4px;
        }

        .sc-log {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            background: #f8fafc;
        }

        .sc-msg {
            display: flex;
            margin-bottom: 10px;
        }

        .sc-me {
            justify-content: flex-end;
        }

        .sc-bubble {
            max-width: 82%;
            padding: 10px 13px;
            border-radius: 14px;
            font-size: 14px;
            line-height: 1.5;
            /* The reply is plain text and may well contain newlines. */
            white-space: pre-wrap;
            word-break: break-word;
        }

        .sc-bot .sc-bubble {
            background: #fff;
            color: #1f2937;
            border: 1px solid #e2e8f0;
            border-bottom-left-radius: 4px;
        }

        .sc-me .sc-bubble {
            background: #00224c;
            color: #fff;
            border-bottom-right-radius: 4px;
        }

        .sc-thinking .sc-bubble {
            color: #94a3b8;
            letter-spacing: 2px;
        }

        .sc-lead {
            border-top: 1px solid #e2e8f0;
            padding: 14px 16px;
            background: #fff;
        }

        .sc-lead p {
            font-size: 13px;
            color: #475569;
            margin: 0 0 10px;
        }

        .sc-lead input {
            width: 100%;
            margin-bottom: 8px;
            padding: 9px 11px;
            border: 1px solid #e2e8f0;
            border-radius: 9px;
            font-size: 13px;
        }

        .sc-lead button {
            width: 100%;
            padding: 10px;
            border: none;
            border-radius: 9px;
            background: #00224c;
            color: #fff;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
        }

        .sc-lead-error {
            color: #dc3545;
            font-size: 12.5px;
            margin-bottom: 8px;
        }

        .sc-ask {
            display: flex;
            gap: 8px;
            padding: 12px;
            border-top: 1px solid #e2e8f0;
            background: #fff;
        }

        .sc-input {
            flex: 1;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            padding: 10px 15px;
            font-size: 14px;
            outline: none;
        }

        .sc-input:focus {
            border-color: #00224c;
        }

        .sc-send {
            width: 40px;
            border: none;
            border-radius: 50%;
            background: #00224c;
            color: #fff;
            cursor: pointer;
        }

        .sc-send:disabled,
        .sc-input:disabled {
            opacity: .6;
        }

        @media (max-width: 420px) {
            #sarismartChat {
                right: 12px;
                bottom: 12px;
            }
        }
    </style>

    <div id="sarismartChat" data-base="<?= htmlspecialchars(rtrim($BASE_URL ?? '', '/') . '/') ?>">

        <div class="sc-panel">

            <div class="sc-head">
                <div>
                    <strong>Ask SariSmart</strong>
                    <div>Plans, pricing and how to sign up</div>
                </div>
                <button type="button" class="sc-close" aria-label="Close">&times;</button>
            </div>

            <div class="sc-log" role="log" aria-live="polite"></div>

            <div class="sc-lead" hidden>
                <form class="sc-lead-form">
                    <p>Leave your details and our team will get in touch.</p>
                    <div class="sc-lead-error" hidden></div>
                    <input type="text" name="name" placeholder="Your name" maxlength="150" required>
                    <input type="text" name="business" placeholder="Business name" maxlength="150" required>
                    <input type="email" name="email" placeholder="Email" maxlength="150">
                    <input type="text" name="phone" placeholder="Phone" maxlength="60">
                    <button type="submit">Send my details</button>
                </form>
            </div>

            <form class="sc-ask">
                <input type="text" class="sc-input" placeholder="Type your question..."
                    maxlength="500" autocomplete="off" aria-label="Your question">
                <button type="submit" class="sc-send" aria-label="Send">
                    <i class="bi bi-send"></i>
                </button>
            </form>

        </div>

        <button type="button" class="sc-toggle" aria-label="Ask SariSmart">
            <i class="bi bi-chat-dots"></i>
        </button>

    </div>

    <script src="<?= $BASE_URL ?>/assets/js/landing-chat.js"></script>

<?php endif; ?>

</body>

</html>
