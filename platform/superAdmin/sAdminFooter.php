</div>
</div>


<script>

    document.addEventListener("DOMContentLoaded", function () {

        // ==========================
        // Mobile Sidebar Toggle
        // ==========================

        const sidebar = document.querySelector(".sidebar");
        const toggle = document.getElementById("toggleSidebar");

        if (toggle) {

            toggle.addEventListener("click", function () {

                sidebar.classList.toggle("show");

            });

        }

        // ==========================
        // Close sidebar when clicking outside
        // ==========================

        document.addEventListener("click", function (e) {

            if (window.innerWidth <= 992) {

                if (
                    !sidebar.contains(e.target) &&
                    !toggle.contains(e.target)
                ) {

                    sidebar.classList.remove("show");

                }

            }

        });

        // ==========================
        // Auto open Website Management
        // if one of its pages is active
        // ==========================

        const activeSub = document.querySelector(".active-sub");

        if (activeSub) {

            const collapse = document.getElementById("websiteMenu");

            if (collapse) {

                const bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapse, {
                    toggle: false
                });

                bsCollapse.show();

            }

        }

    });

</script>

<?php if (!empty($access_denied)): ?>
    <!-- requirePlatformAccess() sent somebody here from a module their role
         does not hold. Saying so beats silently landing them elsewhere. -->
    <script>
        Swal.fire({
            icon: "info",
            title: "Not Your Area",
            text: <?= json_encode($access_denied) ?>,
            confirmButtonColor: "#00224c"
        });
    </script>
<?php endif; ?>

<?php
/*
| The platform assistant.
|
| Drawn only for a signed-in platform role. A tenant never reaches these
| pages, but the check is here as well as at the endpoint because markup is
| the easiest thing in a system to end up somewhere nobody expected.
|
| The token is minted here and posted back by the script, so a page the
| operator did not open cannot ask a question on their behalf.
*/
if (!empty($_SESSION['user_id']) && function_exists('platformRole') && platformRole() !== null) {

    if (empty($_SESSION['admin_chat_csrf'])) {
        $_SESSION['admin_chat_csrf'] = bin2hex(random_bytes(16));
    }
    ?>
    <div id="rcAdminChat" data-token="<?= htmlspecialchars($_SESSION['admin_chat_csrf'], ENT_QUOTES) ?>">

        <button type="button" class="rc-ac-toggle" aria-label="Ask about the platform">
            <i class="bi bi-stars"></i>
        </button>

        <div class="rc-ac-panel" hidden>
            <div class="rc-ac-head">
                <div>
                    <strong>Platform assistant</strong>
                    <div class="rc-ac-sub">Companies, revenue, leads, tickets</div>
                </div>
                <button type="button" class="rc-ac-close" aria-label="Close">&times;</button>
            </div>

            <div class="rc-ac-log"></div>

            <form class="rc-ac-ask" autocomplete="off">
                <input type="text" class="rc-ac-input" maxlength="500"
                    placeholder="How many businesses are waiting for review?">
                <button type="submit" class="rc-ac-send">
                    <i class="bi bi-send"></i>
                </button>
            </form>
        </div>
    </div>

    <style>
        #rcAdminChat { position: fixed; right: 22px; bottom: 22px; z-index: 1080; }

        .rc-ac-toggle {
            width: 56px; height: 56px; border-radius: 50%;
            border: none; background: #00224c; color: #fff;
            font-size: 22px; cursor: pointer;
            box-shadow: 0 10px 28px rgba(0, 34, 76, .35);
            display: flex; align-items: center; justify-content: center;
            transition: transform .15s ease;
        }

        .rc-ac-toggle:hover { transform: translateY(-2px); }

        .rc-ac-panel {
            position: absolute; right: 0; bottom: 70px;
            width: 370px; max-width: calc(100vw - 44px);
            /* Tall enough to read an answer, short enough to stay on a laptop
               screen beside the page it is about. */
            height: 460px; max-height: calc(100vh - 140px);
            background: #fff; border-radius: 16px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, .22);
            display: flex; flex-direction: column; overflow: hidden;
        }

        .rc-ac-head {
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 12px; padding: 14px 16px;
            background: #00224c; color: #fff;
        }

        .rc-ac-sub { font-size: 12px; opacity: .75; margin-top: 2px; }

        .rc-ac-close {
            background: none; border: none; color: #fff;
            font-size: 22px; line-height: 1; cursor: pointer;
        }

        .rc-ac-log {
            flex: 1; overflow-y: auto; padding: 14px 16px;
            font-size: 14px; background: #f7f9fc;
        }

        .rc-ac-row { margin-bottom: 12px; display: flex; }
        .rc-ac-row.me { justify-content: flex-end; }

        .rc-ac-msg {
            max-width: 84%; padding: 9px 13px; border-radius: 13px;
            /* Figures arrive as lists and tables; without this they run
               together into one paragraph. */
            white-space: pre-wrap; word-break: break-word; line-height: 1.5;
        }

        .rc-ac-row.me .rc-ac-msg { background: #00224c; color: #fff; }
        .rc-ac-row.bot .rc-ac-msg { background: #fff; border: 1px solid #e3e9f0; color: #24324a; }

        .rc-ac-ask { display: flex; gap: 8px; padding: 12px; border-top: 1px solid #e9eef5; }

        .rc-ac-input {
            flex: 1; border: 1px solid #d9e0e7; border-radius: 10px;
            padding: 9px 12px; font-size: 14px;
        }

        .rc-ac-input:focus { outline: 2px solid rgba(0, 34, 76, .25); border-color: #00224c; }

        .rc-ac-send {
            border: none; background: #00224c; color: #fff;
            border-radius: 10px; padding: 0 15px; cursor: pointer;
        }

        .rc-ac-send:disabled { opacity: .55; cursor: default; }
    </style>

    <script>
        (function () {
            var root = document.getElementById("rcAdminChat");
            if (!root) { return; }

            var panel = root.querySelector(".rc-ac-panel");
            var log = root.querySelector(".rc-ac-log");
            var form = root.querySelector(".rc-ac-ask");
            var input = root.querySelector(".rc-ac-input");
            var send = root.querySelector(".rc-ac-send");

            function say(text, who) {
                var row = document.createElement("div");
                row.className = "rc-ac-row " + who;

                var msg = document.createElement("div");
                msg.className = "rc-ac-msg";

                /*
                 * textContent, never innerHTML. The answer is written by a
                 * model reading rows a person typed -- a company name, a
                 * support ticket subject -- and a <script> in one of those
                 * must arrive as characters on the screen, not as a tag.
                 */
                msg.textContent = text;

                row.appendChild(msg);
                log.appendChild(row);
                log.scrollTop = log.scrollHeight;

                return row;
            }

            root.querySelector(".rc-ac-toggle").addEventListener("click", function () {
                panel.hidden = !panel.hidden;

                if (!panel.hidden) {
                    if (!log.childElementCount) {
                        say("Ask me about the platform — how many businesses are on it, "
                            + "what is waiting for review, revenue by plan, open tickets, "
                            + "recent leads.", "bot");
                    }
                    input.focus();
                }
            });

            root.querySelector(".rc-ac-close").addEventListener("click", function () {
                panel.hidden = true;
            });

            form.addEventListener("submit", function (e) {
                e.preventDefault();

                var question = input.value.trim();
                if (!question) { return; }

                say(question, "me");
                input.value = "";
                send.disabled = true;

                var waiting = say("…", "bot");

                fetch("/platform/admin_chat.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        question: question,
                        csrf_token: root.getAttribute("data-token")
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    waiting.remove();
                    say(data.ok ? data.answer : (data.message || "I could not answer that."), "bot");
                })
                .catch(function () {
                    waiting.remove();
                    say("I could not reach the server. Please try again.", "bot");
                })
                .finally(function () {
                    send.disabled = false;
                    input.focus();
                });
            });
        })();
    </script>
<?php } ?>

</body>

</html>



<!-- </div>

</div>

<script>
    document.addEventListener("DOMContentLoaded", function () {

        const sidebar = document.querySelector(".sidebar");
        const content = document.querySelector(".main-content");
        const toggle = document.getElementById("sidebarToggle");
        const overlay = document.getElementById("mobileOverlay");

        function mobile() {
            return window.innerWidth < 992;
        }

        /* Restore desktop state */
        if (localStorage.getItem("sidebarCollapsed") === "true" && !mobile()) {
            sidebar.classList.add("collapsed");
            content.classList.add("expanded");
        }

        toggle.addEventListener("click", function () {

            if (mobile()) {

                sidebar.classList.toggle("show");
                overlay.classList.toggle("show");

            } else {

                sidebar.classList.toggle("collapsed");
                content.classList.toggle("expanded");

                localStorage.setItem(
                    "sidebarCollapsed",
                    sidebar.classList.contains("collapsed")
                );

            }

        });

        overlay.addEventListener("click", function () {

            sidebar.classList.remove("show");
            overlay.classList.remove("show");

        });

        window.addEventListener("resize", function () {

            if (!mobile()) {

                sidebar.classList.remove("show");
                overlay.classList.remove("show");

                if (localStorage.getItem("sidebarCollapsed") === "true") {
                    sidebar.classList.add("collapsed");
                    content.classList.add("expanded");
                }

            } else {

                sidebar.classList.remove("collapsed");
                content.classList.remove("expanded");

            }

        });

    });
</script>
</body>

</html> -->